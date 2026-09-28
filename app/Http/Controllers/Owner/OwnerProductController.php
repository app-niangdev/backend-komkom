<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SerialNumber;
use App\Models\Store;
use App\Services\OwnerScopeService;
use App\Services\ProductCatalogService;
use Illuminate\Http\Request;

/**
 * Gestion du catalogue d'une boutique : fiche produit (création, modification, suppression)
 * et création rapide de catégorie depuis le formulaire.
 */
class OwnerProductController extends Controller
{
    public function __construct(protected OwnerScopeService $scope, protected ProductCatalogService $catalog)
    {
    }

    /** Fiche complète pour le formulaire de modification. */
    public function show(Request $request, $id)
    {
        $product = $this->find($request, $id)->load(['store', 'category:id,name', 'unitOfMeasures', 'media']);

        return response()->json(['data' => $this->payload($product)]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request, true);
        $store = $this->ownedStore($request, $validated['store_id']);

        $product = $this->catalog->create($store, $this->data($request, $validated), $request->file('image'), $validated['image_url'] ?? null);

        return response()->json([
            'message' => "Produit « {$product->name} » créé.",
            'data' => $this->payload($product->fresh(['store', 'category:id,name', 'unitOfMeasures', 'media'])),
        ], 201);
    }

    /** POST (multipart) : l'image peut accompagner la modification. */
    public function update(Request $request, $id)
    {
        $product = $this->find($request, $id);
        $validated = $this->validatePayload($request, false);

        $product = $this->catalog->update(
            $product,
            $this->data($request, $validated),
            $request->file('image'),
            $validated['image_url'] ?? null,
            $request->boolean('remove_image')
        );

        return response()->json([
            'message' => "Produit « {$product->name} » mis à jour.",
            'data' => $this->payload($product->fresh(['store', 'category:id,name', 'unitOfMeasures', 'media'])),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $product = $this->find($request, $id);
        $this->catalog->delete($product);

        return response()->json(['message' => "Produit « {$product->name} » supprimé."]);
    }

    public function storeCategory(Request $request)
    {
        $validated = $request->validate([
            'store_id' => 'required|integer',
            'name' => 'required|string|min:2|max:100',
        ], [
            'name.required' => 'Le nom de la catégorie est obligatoire.',
        ]);
        $category = $this->catalog->createCategory($this->ownedStore($request, $validated['store_id']), $validated['name']);

        return response()->json(['data' => ['id' => $category->id, 'name' => $category->name]], 201);
    }

    private function validatePayload(Request $request, bool $creating): array
    {
        return $request->validate(($creating ? ['store_id' => 'required|integer'] : []) + [
            'name' => 'required|string|max:255',
            'category_id' => 'nullable|integer',
            'description' => 'nullable|string|max:1000',
            'alert_threshold' => 'nullable|integer|min:0|max:1000000',
            'require_serial_number' => 'required|boolean',
            'price' => 'nullable|numeric|min:0|max:1000000000',
            'units' => 'nullable|array|max:10',
            'units.*.id' => 'nullable|integer',
            'units.*.name' => 'required|string|max:50',
            'units.*.price' => 'required|numeric|min:0|max:1000000000',
            'units.*.conversion_factor' => 'required|numeric|min:0.001|max:1000000',
            'units.*.is_base_unit' => 'required|boolean',
            'image' => 'nullable|file',
            'image_url' => 'nullable|url|max:2048',
            'remove_image' => 'nullable|boolean',
        ], [
            'store_id.required' => 'Choisissez la boutique du produit.',
            'name.required' => 'Le nom du produit est obligatoire.',
            'units.*.name.required' => 'Chaque unité doit avoir un nom.',
            'units.*.price.required' => 'Chaque unité doit avoir un prix.',
            'units.*.conversion_factor.min' => 'Le facteur de conversion doit être supérieur à zéro.',
            'image_url.url' => 'Le lien de l\'image n\'est pas valide.',
        ]);
    }

    /** Champs métier normalisés (booléens d'un envoi multipart compris). */
    private function data(Request $request, array $validated): array
    {
        return [
            'name' => $validated['name'],
            'category_id' => $validated['category_id'] ?? null,
            'description' => $validated['description'] ?? null,
            'alert_threshold' => $validated['alert_threshold'] ?? 0,
            'require_serial_number' => (bool) $validated['require_serial_number'],
            'price' => $validated['price'] ?? null,
            'units' => collect($validated['units'] ?? [])->map(fn ($u) => [
                'id' => $u['id'] ?? null,
                'name' => $u['name'],
                'price' => $u['price'],
                'conversion_factor' => $u['conversion_factor'],
                'is_base_unit' => filter_var($u['is_base_unit'], FILTER_VALIDATE_BOOLEAN),
            ])->all(),
        ];
    }

    private function payload(Product $product): array
    {
        $serials = $product->require_serial_number
            ? SerialNumber::where('product_id', $product->id)->withHistory()
                ->selectRaw('COUNT(*) FILTER (WHERE is_sold = false) AS in_stock, COUNT(*) FILTER (WHERE is_sold = true) AS sold')
                ->first()
            : null;

        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'store' => $product->store ? ['id' => $product->store->id, 'name' => $product->store->name] : null,
            'category_id' => $product->category_id,
            'category' => $product->category?->name,
            'image_url' => $product->getFirstMediaUrl('image') ?: null,
            'alert_threshold' => (int) $product->alert_threshold,
            'require_serial_number' => (bool) $product->require_serial_number,
            'base_unit' => $product->base_unit,
            'stock' => (float) $product->base_unit_quantity,
            'uses_measurements' => (bool) ($product->store?->uses_measurements ?? true),
            'units' => $product->unitOfMeasures->sortByDesc('is_base_unit')->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'price' => (float) $u->price,
                'conversion_factor' => (float) $u->conversion_factor,
                'is_base_unit' => (bool) $u->is_base_unit,
            ])->values(),
            'serials' => $serials ? ['in_stock' => (int) $serials->in_stock, 'sold' => (int) $serials->sold] : null,
        ];
    }

    /** Produit d'une boutique accessible (abonnement en cours) du périmètre, sinon 404. */
    private function find(Request $request, $id): Product
    {
        $storeIds = $this->scope->resolve($request->merge(['store_id' => null]))['store_ids'];

        return Product::whereIn('store_id', $storeIds)->findOrFail($id);
    }

    private function ownedStore(Request $request, $storeId): Store
    {
        $store = $this->scope->stores($request->user())->firstWhere('id', (int) $storeId);
        abort_if(!$store, 422, 'La boutique choisie ne fait pas partie de vos boutiques.');

        return $store;
    }
}
