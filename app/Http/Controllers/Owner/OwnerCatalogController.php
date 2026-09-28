<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Services\OwnerScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Données de référence des boutiques du propriétaire (consultation) :
 * produits & stock, catégories.
 */
class OwnerCatalogController extends Controller
{
    public function __construct(protected OwnerScopeService $scope)
    {
    }

    public function products(Request $request)
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:100',
            'category_id' => 'nullable|integer',
            'stock' => 'nullable|in:out,low,ok',
            'perPage' => 'nullable|integer|min:1|max:100',
        ]);

        $storeIds = $this->scope->resolve($request)['store_ids'];

        $query = Product::query()
            ->with(['store:id,name', 'category:id,name', 'media', 'unitOfMeasures:id,product_id,name,price,conversion_factor,is_base_unit'])
            ->whereIn('store_id', $storeIds)
            ->when($validated['search'] ?? null, fn ($q, $s) => $q->where('name', 'ILIKE', "%{$s}%"))
            ->when($validated['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when($validated['stock'] ?? null, fn ($q, $state) => match ($state) {
                'out' => $q->where('base_unit_quantity', '<=', 0),
                'low' => $q->where('base_unit_quantity', '>', 0)->whereColumn('base_unit_quantity', '<=', 'alert_threshold'),
                'ok' => $q->whereColumn('base_unit_quantity', '>', 'alert_threshold'),
            });

        // Synthèse sur tout le périmètre (indépendante de la page et des filtres)
        $all = Product::whereIn('store_id', $storeIds);
        $stockValue = DB::table('products')
            ->join('unit_of_measures', function ($join) {
                $join->on('unit_of_measures.product_id', '=', 'products.id')
                    ->where('unit_of_measures.is_base_unit', true)
                    ->whereNull('unit_of_measures.deleted_at');
            })
            ->whereIn('products.store_id', $storeIds)
            ->whereNull('products.deleted_at')
            ->where('products.base_unit_quantity', '>', 0)
            ->sum(DB::raw('products.base_unit_quantity * unit_of_measures.price'));

        $products = $query->orderBy('name')->paginate($validated['perPage'] ?? 20);

        return response()->json([
            'data' => collect($products->items())->map(function (Product $p) {
                $base = $p->unitOfMeasures->firstWhere('is_base_unit', true);
                $quantity = (float) $p->base_unit_quantity;

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'image_url' => $p->getFirstMediaUrl('image') ?: null,
                    'store' => $p->store ? ['id' => $p->store->id, 'name' => $p->store->name] : null,
                    'category' => $p->category?->name,
                    'base_unit' => $p->base_unit,
                    'quantity' => $quantity,
                    'alert_threshold' => (int) $p->alert_threshold,
                    'stock_state' => $quantity <= 0 ? 'out' : ($quantity <= $p->alert_threshold ? 'low' : 'ok'),
                    'price' => $base ? (float) $base->price : null,
                    'stock_value' => $base && $quantity > 0 ? round($quantity * $base->price) : 0,
                    'require_serial_number' => (bool) $p->require_serial_number,
                    // Autres unités de vente (carton, paquet...) avec leur prix
                    'units' => $p->unitOfMeasures
                        ->where('is_base_unit', false)
                        ->map(fn ($u) => [
                            'name' => $u->name,
                            'price' => (float) $u->price,
                            'factor' => (float) $u->conversion_factor,
                        ])->values(),
                ];
            }),
            'meta' => $this->meta($products),
            'summary' => [
                'products' => (clone $all)->count(),
                'out' => (clone $all)->where('base_unit_quantity', '<=', 0)->count(),
                'low' => (clone $all)->where('base_unit_quantity', '>', 0)->whereColumn('base_unit_quantity', '<=', 'alert_threshold')->count(),
                'stock_value' => (float) $stockValue,
            ],
            'currency' => config('subscriptions.default_currency', 'XOF'),
        ]);
    }

    public function categories(Request $request)
    {
        $storeIds = $this->scope->resolve($request)['store_ids'];

        return response()->json([
            'data' => Category::whereIn('store_id', $storeIds)
                ->with('store:id,name')
                ->orderBy('name')
                ->get()
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'store_name' => $c->store?->name]),
        ]);
    }

    private function meta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }
}
