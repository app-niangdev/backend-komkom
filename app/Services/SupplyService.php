<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SerialNumber;
use App\Models\Stock;
use App\Models\Store;
use App\Models\Supply;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cycle de vie d'un approvisionnement :
 * en attente (stock inchangé) → reçu (stock augmenté) ou annulé.
 *
 * Les erreurs métier sont levées en ValidationException (422 avec `errors`).
 *
 * @phpstan-type Line array{product_id: int, quantity: float|int|string, purchase_price: int|float|string, serial_numbers?: ?array}
 */
class SupplyService
{
    /** Crée un approvisionnement en attente : les numéros de série sont réservés, le stock ne bouge pas. */
    public function create(Store $store, int $supplierId, array $lines, int $userId): Supply
    {
        return DB::transaction(function () use ($store, $supplierId, $lines, $userId) {
            $products = $this->checkLines($store, $lines);

            $supply = Supply::create([
                'store_id' => $store->id,
                'supplier_id' => $supplierId,
                'user_id' => $userId,
                'status' => 'pending',
                'total_amount' => 0,
            ]);

            $this->writeLines($supply, $products, $lines);

            return $supply;
        });
    }

    /** Remplace le fournisseur et les lignes d'un approvisionnement encore en attente. */
    public function update(Supply $supply, int $supplierId, array $lines, int $userId): Supply
    {
        return DB::transaction(function () use ($supply, $supplierId, $lines, $userId) {
            $supply = $this->lockPending($supply, 'modifiés');
            $products = $this->checkLines($supply->store, $lines, $supply->id);

            // Suppression définitive : des S/N en corbeille bloqueraient la contrainte unique
            $oldLineIds = $supply->supplyLineItems()->withTrashed()->pluck('id');
            SerialNumber::withTrashed()->whereIn('supply_line_item_id', $oldLineIds)->forceDelete();
            $supply->supplyLineItems()->withTrashed()->forceDelete();

            $supply->update(['supplier_id' => $supplierId, 'user_id' => $userId]);
            $this->writeLines($supply, $products, $lines);

            return $supply;
        });
    }

    /** Réception : entrée en stock de chaque ligne et journal des mouvements. */
    public function receive(Supply $supply, int $userId): Supply
    {
        return DB::transaction(function () use ($supply, $userId) {
            $supply = $this->lockPending($supply, 'réceptionnés');
            $supply->load('supplyLineItems.product');

            $missing = $supply->supplyLineItems->filter(fn ($line) => !$line->product);
            if ($missing->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'line_items' => 'Un produit de cet approvisionnement a été supprimé : modifiez-le avant de le réceptionner.',
                ]);
            }

            // Statut d'abord : le stock des produits à numéro de série ne compte que les S/N d'approvisionnements reçus
            $supply->update(['status' => 'received', 'received_at' => now(), 'received_by' => $userId]);

            foreach ($supply->supplyLineItems as $line) {
                if ($line->product->require_serial_number) {
                    $line->product->syncStockFromSerialNumbers();
                } else {
                    $line->product->increment('base_unit_quantity', $line->quantity);
                }

                Stock::create([
                    'store_id' => $supply->store_id,
                    'product_id' => $line->product_id,
                    'quantity' => $line->quantity,
                    'movement_type' => 'in',
                    'reason' => 'Réception approvisionnement #' . $supply->order_number,
                ]);
            }

            return $supply;
        });
    }

    /** Annulation : libère les numéros de série réservés, le stock n'a jamais bougé. */
    public function cancel(Supply $supply): Supply
    {
        return DB::transaction(function () use ($supply) {
            $supply = $this->lockPending($supply, 'annulés');

            $lineIds = $supply->supplyLineItems()->pluck('id');
            SerialNumber::withTrashed()->whereIn('supply_line_item_id', $lineIds)->forceDelete();
            $supply->update(['status' => 'cancelled']);

            return $supply;
        });
    }

    /** Verrouille la ligne pour éviter une double réception simultanée. */
    private function lockPending(Supply $supply, string $action): Supply
    {
        $locked = Supply::whereKey($supply->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => "Seuls les approvisionnements en attente peuvent être {$action}.",
            ]);
        }

        return $locked;
    }

    /**
     * Contrôles métier des lignes : produits de la boutique, sans doublon, quantités entières
     * quand c'est requis, numéros de série complets et inédits.
     *
     * @return Collection<int, Product> produits indexés par id
     */
    private function checkLines(Store $store, array $lines, ?int $excludeSupplyId = null): Collection
    {
        $ids = collect($lines)->pluck('product_id')->map(fn ($id) => (int) $id);
        if ($ids->count() !== $ids->unique()->count()) {
            $this->fail('Un même produit apparaît sur plusieurs lignes : regroupez les quantités.');
        }

        $products = Product::where('store_id', $store->id)->whereIn('id', $ids)->get()->keyBy('id');
        if ($products->count() !== $ids->count()) {
            $this->fail('Un ou plusieurs produits n\'appartiennent pas à la boutique « ' . $store->name . ' ».');
        }

        $integerOnly = !($store->uses_measurements ?? true);

        foreach ($lines as $line) {
            $product = $products[(int) $line['product_id']];
            $quantity = (float) $line['quantity'];
            $isInteger = $quantity === floor($quantity);

            if ($integerOnly && !$isInteger) {
                $this->fail("« {$product->name} » : la quantité doit être un nombre entier (boutique sans gestion des mesures).");
            }

            if (!$product->require_serial_number) {
                continue;
            }

            if (!$isInteger) {
                $this->fail("« {$product->name} » est suivi par numéro de série : la quantité doit être un nombre entier.");
            }

            $serials = $this->cleanSerials($line['serial_numbers'] ?? []);
            if (count($serials) !== (int) $quantity) {
                $this->fail("« {$product->name} » : " . count($serials) . " numéro(s) de série saisi(s) pour une quantité de " . (int) $quantity . '.');
            }

            $duplicates = array_unique(array_diff_assoc($serials, array_unique($serials)));
            if ($duplicates) {
                $this->fail("« {$product->name} » : numéros de série en double : " . implode(', ', $duplicates) . '.');
            }

            // Les S/N en corbeille comptent aussi : la contrainte unique PostgreSQL les inclut
            $existing = SerialNumber::withTrashed()
                ->where('product_id', $product->id)
                ->whereIn('serial_number', $serials)
                ->when($excludeSupplyId, fn ($q) => $q->where(fn ($sub) => $sub
                    ->whereNull('supply_line_item_id')
                    ->orWhereHas('supplyLineItem', fn ($l) => $l->withTrashed()->where('supply_id', '!=', $excludeSupplyId))))
                ->pluck('serial_number');
            if ($existing->isNotEmpty()) {
                $this->fail("« {$product->name} » : ces numéros de série existent déjà : " . $existing->implode(', ') . '.');
            }

            // Un IMEI identifie un appareil : il ne peut pas exister sous un autre produit de la boutique
            $elsewhere = SerialNumber::withTrashed()
                ->where('product_id', '!=', $product->id)
                ->whereIn(DB::raw('LOWER(serial_number)'), array_map('mb_strtolower', $serials))
                ->whereHas('product', fn ($q) => $q->withTrashed()->where('store_id', $store->id))
                ->with(['product' => fn ($q) => $q->withTrashed()->select('id', 'name')])
                ->get();
            if ($elsewhere->isNotEmpty()) {
                $first = $elsewhere->first();
                $this->fail("« {$product->name} » : le numéro {$first->serial_number} est déjà enregistré pour « {$first->product?->name} ».");
            }
        }

        return $products;
    }

    /**
     * Numéros de série déjà enregistrés dans la boutique (tous produits, casse ignorée) : contrôle
     * immédiat au scan, avant l'enregistrement. Ceux de l'approvisionnement modifié sont ignorés.
     *
     * @param list<string> $serials
     * @return Collection<int, SerialNumber> avec `product` et `supplyLineItem.supply` chargés
     */
    public function serialConflicts(Store $store, array $serials, ?int $excludeSupplyId = null): Collection
    {
        $serials = $this->cleanSerials($serials);
        if (!$serials) {
            return collect();
        }

        return SerialNumber::withTrashed()
            ->whereIn(DB::raw('LOWER(serial_number)'), array_map('mb_strtolower', $serials))
            ->whereHas('product', fn ($q) => $q->withTrashed()->where('store_id', $store->id))
            ->when($excludeSupplyId, fn ($q) => $q->where(fn ($sub) => $sub
                ->whereNull('supply_line_item_id')
                ->orWhereHas('supplyLineItem', fn ($l) => $l->withTrashed()->where('supply_id', '!=', $excludeSupplyId))))
            ->with([
                'product' => fn ($q) => $q->withTrashed()->select('id', 'name'),
                'supplyLineItem' => fn ($q) => $q->withTrashed()->select('id', 'supply_id'),
                'supplyLineItem.supply:id,order_number,status',
            ])
            ->get();
    }

    /** Crée les lignes, réserve les numéros de série et recalcule le total. */
    private function writeLines(Supply $supply, Collection $products, array $lines): void
    {
        $total = 0;
        $serialRows = [];
        $now = now();

        foreach ($lines as $line) {
            $product = $products[(int) $line['product_id']];
            $item = $supply->supplyLineItems()->create([
                'product_id' => $product->id,
                'quantity' => $line['quantity'],
                'purchase_price' => (int) round((float) $line['purchase_price']),
            ]);

            if ($product->require_serial_number) {
                foreach ($this->cleanSerials($line['serial_numbers'] ?? []) as $serial) {
                    $serialRows[] = [
                        'product_id' => $product->id,
                        'supply_line_item_id' => $item->id,
                        'serial_number' => $serial,
                        'is_sold' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            $total += (float) $line['quantity'] * (int) round((float) $line['purchase_price']);
        }

        if ($serialRows) {
            SerialNumber::insert($serialRows);
        }

        $supply->update(['total_amount' => (int) round($total)]);
    }

    /** @return list<string> */
    private function cleanSerials(?array $serials): array
    {
        return array_values(array_filter(array_map(fn ($s) => trim((string) $s), $serials ?? []), fn ($s) => $s !== ''));
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['line_items' => $message]);
    }
}
