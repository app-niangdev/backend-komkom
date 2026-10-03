<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Store;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Devis : création, modification, copie et changements de statut.
 * Les produits ne sont que lus (pour pré-remplir les lignes) : aucun stock ni produit n'est modifié.
 *
 * @phpstan-type Line array{product_id?: ?int, unit_of_measure_id?: ?int, designation?: ?string, unit_name?: ?string, quantity: float|int|string, unit_price: int|float|string}
 */
class QuoteService
{
    /** Durée de validité proposée par défaut (jours). */
    public const DEFAULT_VALIDITY_DAYS = 30;

    /**
     * @param array{customer: array, items: list<Line>, discount?: ?int, valid_until?: ?string, notes?: ?string} $data
     */
    public function create(Store $store, User $user, array $data): Quote
    {
        return DB::transaction(function () use ($store, $user, $data) {
            $quote = new Quote([
                'store_id' => $store->id,
                'user_id' => $user->id,
                'status' => 'draft',
            ]);
            $quote->quote_number = $this->nextNumber($store);

            return $this->fill($quote, $store, $data);
        });
    }

    /** Modification du contenu : seulement tant que le devis est en brouillon ou envoyé. */
    public function update(Quote $quote, array $data): Quote
    {
        $this->ensureEditable($quote);

        return DB::transaction(fn () => $this->fill($quote, $quote->store, $data));
    }

    /** Copie en brouillon, avec un nouveau numéro et une nouvelle validité. */
    public function duplicate(Quote $source, User $user): Quote
    {
        return DB::transaction(function () use ($source, $user) {
            $source->loadMissing(['store', 'items']);
            $copy = $source->replicate(['quote_number', 'status', 'sent_at', 'decided_at', 'created_at', 'updated_at', 'deleted_at']);
            $copy->fill([
                'user_id' => $user->id,
                'source_quote_id' => $source->id,
                'status' => 'draft',
                'valid_until' => now()->addDays(self::DEFAULT_VALIDITY_DAYS)->toDateString(),
            ]);
            $copy->quote_number = $this->nextNumber($source->store);
            $copy->save();

            foreach ($source->items as $item) {
                $copy->items()->create($item->only([
                    'product_id', 'unit_of_measure_id', 'designation', 'unit_name', 'quantity', 'unit_price', 'subtotal', 'position',
                ]));
            }

            return $copy;
        });
    }

    /** Envoi au client (WhatsApp ou autre moyen) : un brouillon passe à « envoyé ». */
    public function markSent(Quote $quote): Quote
    {
        $quote->sent_at = now();
        if ($quote->status === 'draft') {
            $quote->status = 'sent';
        }
        $quote->save();

        return $quote;
    }

    /** Réponse du client, enregistrée par le gérant : définitive. */
    public function decide(Quote $quote, string $decision): Quote
    {
        if ($quote->status !== 'sent') {
            $this->fail('status', $quote->status === 'draft'
                ? 'Envoyez d\'abord le devis au client avant d\'enregistrer sa réponse.'
                : 'La réponse du client est déjà enregistrée pour ce devis.');
        }

        $quote->update(['status' => $decision, 'decided_at' => now()]);

        return $quote;
    }

    public function ensureEditable(Quote $quote): void
    {
        if (!$quote->isEditable()) {
            $this->fail('status', 'Ce devis est ' . ($quote->status === 'accepted' ? 'accepté' : 'refusé')
                . ' : il ne peut plus être modifié. Dupliquez-le pour en faire une nouvelle version.');
        }
    }

    private function fill(Quote $quote, Store $store, array $data): Quote
    {
        $customer = $this->resolveCustomer($store, $data['customer']);
        $lines = $this->prepareLines($store, $data['items']);
        $gross = (int) $lines->sum('subtotal');
        $discount = (int) ($data['discount'] ?? 0);
        if ($discount > $gross) {
            $this->fail('discount', 'La remise ne peut pas dépasser le sous-total (' . number_format($gross, 0, ',', ' ') . ').');
        }

        $quote->fill([
            'customer_id' => $customer->id,
            'valid_until' => $data['valid_until'] ?? null,
            'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
            'gross_amount' => $gross,
            'discount' => $discount,
            'total_amount' => $gross - $discount,
        ]);
        $quote->save();

        $quote->items()->delete();
        $lines->each(fn (array $line, int $i) => $quote->items()->create($line + ['position' => $i]));

        return $quote->load('items');
    }

    /**
     * Lignes du devis : un produit de la boutique (désignation et unité reprises du catalogue si
     * elles ne sont pas saisies) ou une ligne libre. Le prix saisi fait foi.
     *
     * @param list<Line> $lines
     */
    private function prepareLines(Store $store, array $lines): Collection
    {
        $productIds = collect($lines)->pluck('product_id')->filter()->unique();
        $products = Product::where('store_id', $store->id)->whereIn('id', $productIds)->get()->keyBy('id');
        $units = UnitOfMeasure::whereIn('product_id', $products->keys())->get()->keyBy('id');

        return collect($lines)->values()->map(function (array $line, int $i) use ($products, $units) {
            $label = 'Ligne ' . ($i + 1) . ' : ';
            $product = null;
            $unit = null;

            if (!empty($line['product_id'])) {
                $product = $products->get((int) $line['product_id'])
                    ?? $this->fail("items.$i.product_id", $label . 'produit introuvable dans cette boutique.');
            }
            if ($product && !empty($line['unit_of_measure_id'])) {
                $unit = $units->get((int) $line['unit_of_measure_id']);
                if (!$unit || $unit->product_id !== $product->id) {
                    $this->fail("items.$i.unit_of_measure_id", $label . 'unité de vente inconnue pour ce produit.');
                }
            }

            $designation = trim((string) ($line['designation'] ?? '')) ?: $product?->name;
            if (!$designation) {
                $this->fail("items.$i.designation", $label . 'saisissez une désignation.');
            }

            $quantity = round((float) $line['quantity'], 3);
            $unitPrice = (int) round((float) $line['unit_price']);

            return [
                'product_id' => $product?->id,
                'unit_of_measure_id' => $unit?->id,
                'designation' => mb_substr($designation, 0, 255),
                'unit_name' => trim((string) ($line['unit_name'] ?? '')) ?: ($unit?->name ?? $product?->base_unit),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'subtotal' => (int) round($quantity * $unitPrice),
            ];
        });
    }

    /** Client existant de la boutique, ou nouveau (réutilisé si le téléphone est déjà connu). */
    private function resolveCustomer(Store $store, array $customer): Customer
    {
        if (($customer['mode'] ?? null) === 'new') {
            $phone = trim((string) $customer['phone']);

            return Customer::where('store_id', $store->id)->where('phone', $phone)->first()
                ?? Customer::create([
                    'store_id' => $store->id,
                    'name' => trim((string) $customer['name']),
                    'phone' => $phone,
                    'address' => 'N/A',
                ]);
        }

        return Customer::where('store_id', $store->id)->find($customer['id'] ?? 0)
            ?? $this->fail('customer', 'Client introuvable dans cette boutique.');
    }

    /** DEV-AAAA-NNNN, séquence annuelle propre à la boutique (devis supprimés compris). */
    private function nextNumber(Store $store): string
    {
        DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['quote_number_' . $store->id]);

        $prefix = 'DEV-' . now()->format('Y') . '-';
        $last = Quote::withTrashed()
            ->where('store_id', $store->id)
            ->where('quote_number', 'LIKE', $prefix . '%')
            ->orderByRaw('LENGTH(quote_number) DESC, quote_number DESC')
            ->value('quote_number');

        $sequence = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;

        return $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
