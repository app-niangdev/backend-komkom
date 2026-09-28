<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Services\OwnerScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Catégories d'une boutique : liste avec le nombre de produits et l'état du stock,
 * création, modification et suppression (les produits sont d'abord déplacés).
 */
class OwnerCategoryController extends Controller
{
    public function __construct(protected OwnerScopeService $scope)
    {
    }

    public function index(Request $request)
    {
        $filters = $request->validate(['search' => 'nullable|string|max:100']);
        $scope = $this->scope->resolve($request);

        $stats = DB::table('products')
            ->whereNull('deleted_at')
            ->groupBy('category_id')
            ->selectRaw("
                category_id,
                COUNT(*) AS products,
                COUNT(*) FILTER (WHERE base_unit_quantity <= 0) AS out_of_stock,
                COUNT(*) FILTER (WHERE base_unit_quantity > 0 AND base_unit_quantity <= alert_threshold) AS low_stock
            ");

        $categories = Category::query()
            ->whereIn('categories.store_id', $scope['store_ids'])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($sub) => $sub
                ->where('categories.name', 'ILIKE', "%{$s}%")
                ->orWhere('categories.description', 'ILIKE', "%{$s}%")))
            ->leftJoinSub($stats, 'st', 'st.category_id', '=', 'categories.id')
            ->join('stores', 'stores.id', '=', 'categories.store_id')
            ->orderBy('categories.name')
            ->get([
                'categories.id', 'categories.name', 'categories.description', 'categories.store_id',
                'stores.name AS store_name', 'categories.created_at',
                'st.products', 'st.out_of_stock', 'st.low_stock',
            ]);

        $uncategorized = Product::whereIn('store_id', $scope['store_ids'])->whereNull('category_id')->count();

        return response()->json([
            'data' => $categories->map(fn ($c) => $this->payload($c)),
            'summary' => [
                'categories' => $categories->count(),
                'products' => (int) $categories->sum('products') + $uncategorized,
                'uncategorized' => $uncategorized,
                'empty' => $categories->where('products', null)->count(),
            ],
            'excluded_stores' => $scope['excluded'],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request, true);
        $store = $this->ownedStore($request, $validated['store_id']);
        $this->assertUniqueName($store->id, $validated['name']);

        $category = Category::create([
            'store_id' => $store->id,
            'name' => trim($validated['name']),
            'description' => $this->clean($validated['description'] ?? null),
        ]);

        return response()->json(['message' => "Catégorie « {$category->name} » créée.", 'data' => ['id' => $category->id, 'name' => $category->name]], 201);
    }

    public function update(Request $request, $id)
    {
        $category = $this->find($request, $id);
        $validated = $this->validatePayload($request, false);
        $this->assertUniqueName($category->store_id, $validated['name'], $category->id);

        $category->update([
            'name' => trim($validated['name']),
            'description' => $this->clean($validated['description'] ?? null),
        ]);

        return response()->json(['message' => "Catégorie « {$category->name} » mise à jour."]);
    }

    /**
     * Suppression : une catégorie qui contient des produits exige de choisir où ils vont
     * (`move_to` = une autre catégorie de la boutique, ou « none » pour les laisser sans catégorie).
     */
    public function destroy(Request $request, $id)
    {
        $category = $this->find($request, $id);
        $moveTo = $request->validate(['move_to' => 'nullable|string'])['move_to'] ?? null;
        $count = Product::where('category_id', $category->id)->count();

        if ($count > 0 && $moveTo === null) {
            throw ValidationException::withMessages([
                'move_to' => "« {$category->name} » contient {$count} produit(s) : choisissez la catégorie qui les accueille.",
            ]);
        }

        $target = null;
        if ($count > 0 && $moveTo !== 'none') {
            $target = Category::where('store_id', $category->store_id)->whereKeyNot($category->id)->find((int) $moveTo);
            if (!$target) {
                throw ValidationException::withMessages(['move_to' => 'La catégorie de destination n\'appartient pas à la boutique.']);
            }
        }

        DB::transaction(function () use ($category, $target, $count) {
            if ($count > 0) {
                // Produits archivés compris : leur historique garde une catégorie valide
                Product::withTrashed()->where('category_id', $category->id)->update(['category_id' => $target?->id]);
            }
            $category->delete();
        });

        $moved = $count === 0 ? '' : ($target ? " {$count} produit(s) déplacé(s) vers « {$target->name} »." : " {$count} produit(s) désormais sans catégorie.");

        return response()->json(['message' => "Catégorie « {$category->name} » supprimée." . $moved]);
    }

    private function validatePayload(Request $request, bool $creating): array
    {
        return $request->validate(($creating ? ['store_id' => 'required|integer'] : []) + [
            'name' => 'required|string|min:2|max:100',
            'description' => 'nullable|string|max:255',
        ], [
            'store_id.required' => 'Choisissez la boutique de la catégorie.',
            'name.required' => 'Le nom de la catégorie est obligatoire.',
            'name.min' => 'Le nom doit contenir au moins 2 caractères.',
            'name.max' => 'Le nom ne peut pas dépasser 100 caractères.',
            'description.max' => 'La description ne peut pas dépasser 255 caractères.',
        ]);
    }

    private function assertUniqueName(int $storeId, string $name, ?int $ignoreId = null): void
    {
        $exists = Category::where('store_id', $storeId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => 'Une catégorie porte déjà ce nom dans la boutique.']);
        }
    }

    private function payload(object $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'description' => $c->description,
            'store' => ['id' => $c->store_id, 'name' => $c->store_name],
            'products' => (int) ($c->products ?? 0),
            'out_of_stock' => (int) ($c->out_of_stock ?? 0),
            'low_stock' => (int) ($c->low_stock ?? 0),
        ];
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** Catégorie d'une boutique accessible du périmètre, sinon 404. */
    private function find(Request $request, $id): Category
    {
        $storeIds = $this->scope->resolve($request->merge(['store_id' => null]))['store_ids'];

        return Category::whereIn('store_id', $storeIds)->findOrFail($id);
    }

    private function ownedStore(Request $request, $storeId): Store
    {
        $store = $this->scope->stores($request->user())->firstWhere('id', (int) $storeId);
        abort_if(!$store, 422, 'La boutique choisie ne fait pas partie de vos boutiques.');

        return $store;
    }
}
