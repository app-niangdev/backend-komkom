<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentReceipt;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SerialNumber;
use App\Models\Stock;
use App\Models\Store;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vente au comptoir et encaissement des factures.
 *
 * Une vente enregistrée en caisse est validée immédiatement : le stock sort, la facture est
 * émise et le paiement éventuel est enregistré, le tout dans une seule transaction.
 * Un client identifié peut ne payer qu'une partie (le reste est dû sur la facture) ;
 * une vente anonyme doit être soldée sur-le-champ.
 *
 * Les erreurs métier sont levées en ValidationException (422 avec `errors`).
 *
 * @phpstan-type Line array{product_id: int, unit_of_measure_id?: ?int, quantity: float|int|string, unit_price: int|float|string, serial_numbers?: ?array}
 */
class SaleService
{
    public const PAYMENT_TYPES = ['cash', 'wave', 'OM', 'other'];

    /**
     * @param array{mode: string, id?: ?int, name?: ?string, phone?: ?string} $customer
     * @param list<Line> $lines
     * @param array{amount: int|float, payment_type: string} $payment
     */
    public function checkout(Store $store, User $seller, array $customer, array $lines, int $discount, array $payment): Sale
    {
        return DB::transaction(function () use ($store, $seller, $customer, $lines, $discount, $payment) {
            // Sérialise les ventes d'une boutique : numéro de vente et stock cohérents
            Store::whereKey($store->id)->lockForUpdate()->first();

            $prepared = $this->prepareLines($store, $lines);
            $gross = (int) $prepared->sum('subtotal');

            if ($discount > $gross) {
                $this->fail('discount', 'La remise ne peut pas dépasser le montant des articles.');
            }
            $total = $gross - $discount;

            $customerModel = $this->resolveCustomer($store, $customer);
            $paid = (int) round((float) ($payment['amount'] ?? 0));

            if ($paid < 0 || $paid > $total) {
                $this->fail('payment', 'Le montant encaissé doit être compris entre 0 et le total de la vente.');
            }
            if (!$customerModel && $paid < $total) {
                $this->fail('payment', 'Une vente sans client doit être payée en totalité. Identifiez le client pour lui faire crédit.');
            }

            $sale = Sale::create([
                'store_id' => $store->id,
                'seller_id' => $seller->id,
                'sale_number' => $this->nextSaleNumber($store),
                'gross_amount' => $gross,
                'discount' => $discount,
                'total_amount' => $total,
                'status' => 'confirmed',
                'status_payment' => $this->paymentStatus($paid, $total),
                'customer_id' => $customerModel?->id,
            ]);

            foreach ($prepared as $line) {
                $this->writeLine($sale, $line);
            }

            $invoice = $this->createInvoice($sale, $customerModel, $paid);

            if ($paid > 0) {
                PaymentReceipt::create([
                    'date' => now()->toDateString(),
                    'amount' => $paid,
                    'invoice_id' => $invoice->id,
                    'payment_type' => $payment['payment_type'],
                    'user_id' => $seller->id,
                ]);
            }

            return $sale;
        });
    }

    /** Encaissement (partiel ou total) d'une facture restant due. */
    public function recordPayment(Invoice $invoice, int $amount, string $type, string $date, User $user): Invoice
    {
        return DB::transaction(function () use ($invoice, $amount, $type, $date, $user) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $invoice->load('sale');

            if ($invoice->is_cancelled || $invoice->invoice_status === 'cancelled' || $invoice->sale?->status !== 'confirmed') {
                $this->fail('invoice', 'Cette facture est annulée : aucun paiement ne peut y être enregistré.');
            }
            if ($invoice->balance <= 0) {
                $this->fail('invoice', 'Cette facture est déjà soldée.');
            }
            if ($amount < 1 || $amount > $invoice->balance) {
                $this->fail('amount', 'Le montant doit être compris entre 1 et le reste à payer (' . number_format($invoice->balance, 0, ',', ' ') . ').');
            }

            PaymentReceipt::create([
                'date' => $date,
                'amount' => $amount,
                'invoice_id' => $invoice->id,
                'payment_type' => $type,
                'user_id' => $user->id,
            ]);

            $paid = (int) $invoice->paymentReceipts()->sum('amount');
            $total = (int) $invoice->amount_total;
            $status = $this->paymentStatus($paid, $total);

            $invoice->update([
                'amount_paid' => $paid,
                'balance' => max(0, $total - $paid),
                'invoice_status' => $status,
            ]);
            $invoice->sale->update(['status_payment' => $status]);

            return $invoice;
        });
    }

    /**
     * Contrôles métier des lignes : produits et unités de la boutique, quantités entières
     * quand c'est requis, stock suffisant (toutes lignes d'un même produit cumulées),
     * numéros de série disponibles.
     *
     * @return Collection<int, array{product: Product, unit: ?UnitOfMeasure, quantity: float, base_quantity: float, unit_price: int, subtotal: int, serials: list<string>}>
     */
    private function prepareLines(Store $store, array $lines): Collection
    {
        if (empty($lines)) {
            $this->fail('items', 'Ajoutez au moins un article.');
        }

        $productIds = collect($lines)->pluck('product_id')->map(fn ($id) => (int) $id)->unique();
        $products = Product::where('store_id', $store->id)
            ->whereIn('id', $productIds)
            ->with('unitOfMeasures')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($products->count() !== $productIds->count()) {
            $this->fail('items', 'Un ou plusieurs articles n\'appartiennent pas à la boutique « ' . $store->name . ' ».');
        }

        $integerOnly = !($store->uses_measurements ?? true);
        $seen = [];
        $prepared = collect();

        foreach ($lines as $line) {
            $product = $products[(int) $line['product_id']];
            $unitId = isset($line['unit_of_measure_id']) ? (int) $line['unit_of_measure_id'] : null;

            $key = $product->id . ':' . ($unitId ?? 'base');
            if (isset($seen[$key])) {
                $this->fail('items', "« {$product->name} » apparaît deux fois dans le panier : regroupez les quantités.");
            }
            $seen[$key] = true;

            $unit = $unitId
                ? $product->unitOfMeasures->firstWhere('id', $unitId)
                : $product->unitOfMeasures->firstWhere('is_base_unit', true);
            if ($unitId && !$unit) {
                $this->fail('items', "« {$product->name} » : unité de vente inconnue.");
            }

            $quantity = (float) $line['quantity'];
            $isInteger = $quantity === floor($quantity);
            if ($quantity <= 0) {
                $this->fail('items', "« {$product->name} » : la quantité doit être supérieure à zéro.");
            }
            if ($integerOnly && !$isInteger) {
                $this->fail('items', "« {$product->name} » : la quantité doit être un nombre entier (boutique sans gestion des mesures).");
            }

            $factor = $unit ? (float) $unit->conversion_factor : 1.0;
            $serials = [];

            if ($product->require_serial_number) {
                if (!$isInteger || $factor != 1.0) {
                    $this->fail('items', "« {$product->name} » est suivi par numéro de série : vendez-le à l'unité, en quantité entière.");
                }
                $serials = $this->cleanSerials($line['serial_numbers'] ?? []);
                if (count($serials) !== (int) $quantity || count(array_unique($serials)) !== count($serials)) {
                    $this->fail('items', "« {$product->name} » : choisissez " . (int) $quantity . ' numéro(s) de série distinct(s).');
                }
                $available = SerialNumber::where('product_id', $product->id)
                    ->whereIn('serial_number', $serials)
                    ->available()
                    ->count();
                if ($available !== count($serials)) {
                    $this->fail('items', "« {$product->name} » : un ou plusieurs numéros de série ne sont plus disponibles.");
                }
            }

            $unitPrice = (int) round((float) $line['unit_price']);
            if ($unitPrice < 0) {
                $this->fail('items', "« {$product->name} » : le prix ne peut pas être négatif.");
            }

            $prepared->push([
                'product' => $product,
                'unit' => $unit,
                'quantity' => $quantity,
                'base_quantity' => round($quantity * $factor, 3),
                'unit_price' => $unitPrice,
                'subtotal' => (int) round($quantity * $unitPrice),
                'serials' => $serials,
            ]);
        }

        // Stock : cumul des lignes d'un même produit (vendu en plusieurs unités)
        foreach ($prepared->groupBy(fn ($l) => $l['product']->id) as $group) {
            $product = $group->first()['product'];
            if ($product->require_serial_number) {
                continue;
            }
            $needed = $group->sum('base_quantity');
            if ($needed > (float) $product->base_unit_quantity + 1e-9) {
                $this->fail('items', "Stock insuffisant pour « {$product->name} » : "
                    . $this->formatQuantity((float) $product->base_unit_quantity) . " {$product->base_unit} disponible(s).");
            }
        }

        return $prepared;
    }

    private function writeLine(Sale $sale, array $line): void
    {
        /** @var Product $product */
        $product = $line['product'];

        $item = $sale->saleLineItems()->create([
            'product_id' => $product->id,
            'unit_of_measure_id' => $line['unit']?->id,
            'unit_name' => $line['unit']?->name ?? $product->base_unit,
            'quantity' => $line['quantity'],
            'base_quantity' => $line['base_quantity'],
            'unit_price' => $line['unit_price'],
            'subtotal' => $line['subtotal'],
        ]);

        if ($product->require_serial_number) {
            SerialNumber::where('product_id', $product->id)
                ->whereIn('serial_number', $line['serials'])
                ->update(['sale_line_item_id' => $item->id, 'is_sold' => true]);
            $product->syncStockFromSerialNumbers();
        } else {
            $product->decrement('base_unit_quantity', $line['base_quantity']);
        }

        Stock::create([
            'store_id' => $sale->store_id,
            'product_id' => $product->id,
            'quantity' => $line['base_quantity'],
            'movement_type' => 'out',
            'reason' => 'Vente ' . $sale->sale_number,
        ]);
    }

    /** Client anonyme, existant de la boutique, ou nouveau (réutilisé si le téléphone est déjà connu). */
    private function resolveCustomer(Store $store, array $customer): ?Customer
    {
        return match ($customer['mode'] ?? 'anonymous') {
            'existing' => Customer::where('store_id', $store->id)->find($customer['id'] ?? 0)
                ?? $this->fail('customer', 'Client introuvable dans cette boutique.'),
            'new' => Customer::where('store_id', $store->id)->where('phone', trim((string) $customer['phone']))->first()
                ?? Customer::create([
                    'store_id' => $store->id,
                    'name' => trim((string) $customer['name']),
                    'phone' => trim((string) $customer['phone']),
                    'address' => 'N/A',
                ]),
            default => null,
        };
    }

    private function createInvoice(Sale $sale, ?Customer $customer, int $paid): Invoice
    {
        // Numéro de facture global : un seul émetteur à la fois
        DB::statement("SELECT pg_advisory_xact_lock(hashtext('invoice_number'))");

        return Invoice::create([
            'store_id' => $sale->store_id,
            'sale_id' => $sale->id,
            'customer_id' => $customer?->id,
            'customer_name' => $customer?->name ?? 'Anonyme',
            'invoice_status' => $this->paymentStatus($paid, (int) $sale->total_amount),
            'amount_total' => $sale->total_amount,
            'amount_paid' => $paid,
            'balance' => $sale->total_amount - $paid,
        ]);
    }

    /** VNT-AAAAMMJJ-{boutique}-{séquence du jour} ; appelé sous verrou de la boutique. */
    private function nextSaleNumber(Store $store): string
    {
        $prefix = sprintf('VNT-%s-%d-', now()->format('Ymd'), $store->id);
        $last = Sale::withTrashed()
            ->where('store_id', $store->id)
            ->where('sale_number', 'LIKE', $prefix . '%')
            ->orderByRaw('LENGTH(sale_number) DESC, sale_number DESC')
            ->value('sale_number');

        $sequence = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;

        return $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    private function paymentStatus(int $paid, int $total): string
    {
        return match (true) {
            $total === 0 || $paid >= $total => 'paid',
            $paid > 0 => 'partial',
            default => 'no_paid',
        };
    }

    /** @return list<string> */
    private function cleanSerials(?array $serials): array
    {
        return array_values(array_filter(array_map(fn ($s) => trim((string) $s), $serials ?? []), fn ($s) => $s !== ''));
    }

    private function formatQuantity(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, ',', ' '), '0'), ',');
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
