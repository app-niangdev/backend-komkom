<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Services\StorefrontService;
use App\Support\WhatsappNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * API publique (sans authentification) de la vitrine d'une boutique, identifiée par son slug.
 * N'expose que des informations destinées aux clients : jamais de prix d'achat, fournisseur,
 * numéro de série ni quantité exacte en stock.
 */
class StorefrontController extends Controller
{
    public function __construct(protected StorefrontService $storefront)
    {
    }

    /** Identité de la boutique : contacts, logo, couleurs. */
    public function show(string $slug): JsonResponse
    {
        $store = $this->storefront->findOnline($slug);

        return $this->cached([
            'data' => [
                'slug' => $store->slug,
                'name' => $store->name,
                'slogan' => $store->slogan,
                'company' => $store->company?->name,
                'address' => $store->address,
                'email' => $store->email,
                'phones' => array_values(array_filter([$store->phone_one, $store->phone_two, $store->phone_three])),
                'whatsapp' => WhatsappNumber::normalize($store->phone_one),
                'logo_url' => $store->logo_url,
                'primary_color' => $store->effective_primary_color,
                'secondary_color' => $store->effective_secondary_color,
                'currency' => config('subscriptions.default_currency', 'XOF'),
                'products_count' => $this->storefront->publishedProducts($store)->count(),
            ],
        ]);
    }

    /** Catégories ayant au moins un produit en stock. */
    public function categories(string $slug): JsonResponse
    {
        $store = $this->storefront->findOnline($slug);
        $counts = $this->storefront->publishedProducts($store)
            ->whereNotNull('category_id')
            ->selectRaw('category_id, COUNT(*) AS n')
            ->groupBy('category_id')
            ->pluck('n', 'category_id');

        return $this->cached([
            'data' => Category::where('store_id', $store->id)
                ->whereIn('id', $counts->keys())
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->name, 'products_count' => (int) $counts[$c->id]]),
        ]);
    }

    public function products(Request $request, string $slug): JsonResponse
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:100',
            'category_id' => 'nullable|integer',
            'sort' => 'nullable|in:name,price_asc,price_desc,recent',
            'page' => 'nullable|integer|min:1',
            'perPage' => 'nullable|integer|min:1|max:48',
        ]);
        $store = $this->storefront->findOnline($slug);

        // Prix affiché = prix de l'unité de base
        $basePrice = '(SELECT price FROM unit_of_measures u WHERE u.product_id = products.id AND u.is_base_unit = true AND u.deleted_at IS NULL LIMIT 1)';

        $products = $this->storefront->publishedProducts($store)
            ->with($this->relations())
            ->when($validated['search'] ?? null, fn ($q, $s) => $q->where(fn ($sub) => $sub
                ->where('name', 'ILIKE', "%{$s}%")
                ->orWhere('description', 'ILIKE', "%{$s}%")))
            ->when($validated['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when($validated['sort'] ?? 'name', fn ($q, $sort) => match ($sort) {
                'price_asc' => $q->orderByRaw("{$basePrice} ASC NULLS LAST"),
                'price_desc' => $q->orderByRaw("{$basePrice} DESC NULLS LAST"),
                'recent' => $q->orderByDesc('created_at'),
                default => $q,
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($validated['perPage'] ?? 24);

        return $this->cached([
            'data' => collect($products->items())->map(fn (Product $p) => $this->present($p)),
            'meta' => [
                'current_page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'last_page' => $products->lastPage(),
            ],
        ]);
    }

    public function product(string $slug, $id): JsonResponse
    {
        $store = $this->storefront->findOnline($slug);
        $product = $this->storefront->publishedProducts($store)->with($this->relations())->whereKey((int) $id)->first();
        abort_if(!$product, 404, 'Ce produit n\'est plus disponible.');

        return $this->cached(['data' => $this->present($product, true)]);
    }

    private function relations(): array
    {
        return ['category:id,name', 'media', 'unitOfMeasures:id,product_id,name,price,conversion_factor,is_base_unit'];
    }

    /** Fiche publique : disponibilité sous forme d'indicateur, unités de vente avec leur prix. */
    private function present(Product $p, bool $detailed = false): array
    {
        $stock = (float) $p->base_unit_quantity;
        $units = $p->unitOfMeasures
            ->sortByDesc('is_base_unit')
            ->values()
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'price' => (int) $u->price,
                'is_base' => (bool) $u->is_base_unit,
            ]);
        $base = $units->firstWhere('is_base', true);

        return [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description === null || $detailed ? $p->description : Str::limit($p->description, 140),
            'image_url' => $p->getFirstMediaUrl('image') ?: null,
            'category' => $p->category ? ['id' => $p->category->id, 'name' => $p->category->name] : null,
            'unit' => $p->base_unit,
            'price' => $base['price'] ?? null,
            'availability' => $stock <= $p->alert_threshold ? 'low' : 'in_stock',
            'units' => $units,
        ];
    }

    private function cached(array $payload): JsonResponse
    {
        return response()->json($payload)
            ->setPublic()
            ->setMaxAge(config('storefront.cache_seconds'));
    }
}
