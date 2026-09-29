<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\SupplyLineItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fiche produit d'une boutique : informations, unités de vente, suivi par numéro de série (IMEI) et image.
 *
 * Le stock ne se saisit jamais ici : il vient des approvisionnements reçus et des ventes.
 * Pour un produit suivi par numéro de série, le stock est le nombre de numéros non vendus ;
 * il se vend à l'unité (pas d'autre unité de vente).
 *
 * @phpstan-type Unit array{id?: ?int, name: string, price: int|float|string, conversion_factor: int|float|string, is_base_unit: bool}
 */
class ProductCatalogService
{
    public function __construct(protected FileValidationService $files)
    {
    }

    /**
     * @param array{name: string, category_id: ?int, description: ?string, alert_threshold: ?int, require_serial_number: bool, units?: list<Unit>, price?: ?int} $data
     */
    public function create(Store $store, array $data, ?UploadedFile $image, ?string $imageUrl): Product
    {
        return DB::transaction(function () use ($store, $data, $image, $imageUrl) {
            $this->checkIdentity($store, $data);
            $this->checkSerialAllowed($store, (bool) $data['require_serial_number']);
            $units = $this->normalizeUnits($store, $data);

            $product = Product::create([
                'store_id' => $store->id,
                'category_id' => $data['category_id'] ?? null,
                'name' => trim($data['name']),
                'description' => $data['description'] ?? null,
                'require_serial_number' => $data['require_serial_number'],
                'alert_threshold' => (int) ($data['alert_threshold'] ?? 0),
                'base_unit' => $units->firstWhere('is_base_unit', true)['name'],
                'base_unit_quantity' => 0,
            ]);

            foreach ($units as $unit) {
                $product->unitOfMeasures()->create(collect($unit)->except('id')->all());
            }

            $this->storeImage($product, $image, $imageUrl);

            return $product;
        });
    }

    public function update(Product $product, array $data, ?UploadedFile $image, ?string $imageUrl, bool $removeImage): Product
    {
        return DB::transaction(function () use ($product, $data, $image, $imageUrl, $removeImage) {
            $product = Product::with('unitOfMeasures')->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $store = $product->store;

            $this->checkIdentity($store, $data, $product->id);
            $this->checkSerialSwitch($product, (bool) $data['require_serial_number']);
            if (!$product->require_serial_number) {
                $this->checkSerialAllowed($store, (bool) $data['require_serial_number']);
            }
            $units = $this->normalizeUnits($store, $data);

            // Le stock est compté dans l'unité de base : elle ne change pas tant qu'il en reste
            $currentBase = $product->unitOfMeasures->firstWhere('is_base_unit', true);
            $newBase = $units->firstWhere('is_base_unit', true);
            if ($currentBase && (float) $product->base_unit_quantity > 0 && ($newBase['id'] ?? null) !== $currentBase->id) {
                $this->fail('units', "L'unité de base (« {$currentBase->name} ») ne peut pas changer tant qu'il reste du stock : renommez-la plutôt.");
            }

            $product->update([
                'category_id' => $data['category_id'] ?? null,
                'name' => trim($data['name']),
                'description' => $data['description'] ?? null,
                'require_serial_number' => $data['require_serial_number'],
                'alert_threshold' => (int) ($data['alert_threshold'] ?? 0),
                'base_unit' => $newBase['name'],
            ]);

            $this->syncUnits($product, $units);

            if ($removeImage && !$image && !$imageUrl) {
                $product->clearMediaCollection('image');
            }
            $this->storeImage($product, $image, $imageUrl);

            return $product;
        });
    }

    /** Suppression (archivage) : l'historique des ventes et approvisionnements reste consultable. */
    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product) {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();

            if ((float) $product->base_unit_quantity > 0) {
                $this->fail('product', "Impossible de supprimer « {$product->name} » : il reste "
                    . rtrim(rtrim(number_format((float) $product->base_unit_quantity, 3, ',', ' '), '0'), ',')
                    . " {$product->base_unit} en stock.");
            }

            $pending = SupplyLineItem::where('product_id', $product->id)
                ->whereHas('supply', fn ($q) => $q->where('status', 'pending'))
                ->exists();
            if ($pending) {
                $this->fail('product', "Impossible de supprimer « {$product->name} » : un approvisionnement en attente le contient.");
            }

            $product->delete();
        });
    }

    public function createCategory(Store $store, string $name): Category
    {
        $name = trim($name);
        $existing = Category::where('store_id', $store->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

        return $existing ?? Category::create(['store_id' => $store->id, 'name' => $name]);
    }

    /** Nom unique dans la boutique ; catégorie de la boutique. */
    private function checkIdentity(Store $store, array $data, ?int $ignoreId = null): void
    {
        $duplicate = Product::where('store_id', $store->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
        if ($duplicate) {
            $this->fail('name', 'Un produit porte déjà ce nom dans la boutique « ' . $store->name . ' ».');
        }

        if (!empty($data['category_id']) && !Category::where('store_id', $store->id)->whereKey($data['category_id'])->exists()) {
            $this->fail('category_id', 'Cette catégorie n\'appartient pas à la boutique.');
        }
    }

    /** Boutique qui n'utilise pas les numéros de série : aucun produit ne peut en demander. */
    private function checkSerialAllowed(Store $store, bool $requireSerial): void
    {
        if ($requireSerial && !($store->uses_serial_numbers ?? true)) {
            $this->fail('require_serial_number', 'La boutique « ' . $store->name . ' » n\'utilise pas les numéros de série.');
        }
    }

    /**
     * Activer ou retirer le suivi par numéro de série changerait la façon de compter le stock :
     * seulement possible pour un produit sans stock ni numéro enregistré.
     */
    private function checkSerialSwitch(Product $product, bool $requireSerial): void
    {
        if ($requireSerial === (bool) $product->require_serial_number) {
            return;
        }
        if ((float) $product->base_unit_quantity > 0) {
            $this->fail('require_serial_number', 'Le suivi par numéro de série ne peut pas être modifié tant que le produit a du stock.');
        }
        if (!$requireSerial && $product->serialNumbers()->exists()) {
            $this->fail('require_serial_number', 'Ce produit a déjà des numéros de série enregistrés : le suivi ne peut plus être retiré.');
        }
    }

    /**
     * Unités de vente : une seule unité de base (facteur 1), noms distincts.
     * Boutique sans gestion des mesures, ou produit à numéro de série : une seule unité, à la pièce.
     *
     * @return Collection<int, Unit>
     */
    private function normalizeUnits(Store $store, array $data): Collection
    {
        $usesMeasurements = (bool) ($store->uses_measurements ?? true);
        $units = collect($data['units'] ?? []);

        if (!$usesMeasurements || $data['require_serial_number']) {
            $base = $units->firstWhere('is_base_unit', true) ?? $units->first() ?? [];
            if (!isset($base['price']) && !isset($data['price'])) {
                $this->fail('units', 'Le prix de vente est obligatoire.');
            }

            return collect([[
                'id' => $base['id'] ?? null,
                'name' => $usesMeasurements ? trim((string) ($base['name'] ?? '')) ?: Product::DEFAULT_UNIT_WITHOUT_MEASUREMENTS : Product::DEFAULT_UNIT_WITHOUT_MEASUREMENTS,
                'price' => (int) round((float) ($base['price'] ?? $data['price'])),
                'conversion_factor' => 1,
                'is_base_unit' => true,
            ]]);
        }

        if ($units->isEmpty()) {
            $this->fail('units', 'Ajoutez au moins une unité de vente.');
        }
        if ($units->where('is_base_unit', true)->count() !== 1) {
            $this->fail('units', 'Choisissez une et une seule unité de base.');
        }
        $names = $units->map(fn ($u) => mb_strtolower(trim((string) $u['name'])));
        if ($names->unique()->count() !== $names->count()) {
            $this->fail('units', 'Deux unités portent le même nom.');
        }

        return $units->map(fn ($u) => [
            'id' => isset($u['id']) ? (int) $u['id'] : null,
            'name' => trim((string) $u['name']),
            'price' => (int) round((float) $u['price']),
            'conversion_factor' => $u['is_base_unit'] ? 1 : (float) $u['conversion_factor'],
            'is_base_unit' => (bool) $u['is_base_unit'],
        ])->values();
    }

    /** Met à jour les unités existantes (par id), crée les nouvelles, supprime les retirées. */
    private function syncUnits(Product $product, Collection $units): void
    {
        $existing = $product->unitOfMeasures->keyBy('id');
        $keep = [];

        foreach ($units as $unit) {
            $attributes = collect($unit)->except('id')->all();
            if ($unit['id'] && $existing->has($unit['id'])) {
                $existing[$unit['id']]->update($attributes);
                $keep[] = $unit['id'];
            } else {
                $keep[] = $product->unitOfMeasures()->create($attributes)->id;
            }
        }

        $product->unitOfMeasures()->whereNotIn('id', $keep)->delete();
    }

    private function storeImage(Product $product, ?UploadedFile $image, ?string $imageUrl): void
    {
        if ($image) {
            $this->files->validateAndStoreFile($image, $product, 'image');
        } elseif ($imageUrl) {
            $this->files->validateAndStoreFileFromUrl($imageUrl, $product, 'image');
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
