<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\Supplierproduct;
use App\Models\Supply;
use App\Services\OwnerScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Fournisseurs des boutiques du propriétaire : annuaire avec historique d'achats,
 * fiche détaillée, création, modification et suppression.
 */
class OwnerSupplierController extends Controller
{
    public function __construct(protected OwnerScopeService $scope)
    {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:100',
            'sort' => 'nullable|in:name,purchases,recent',
        ]);
        $storeIds = $this->scope->resolve($request)['store_ids'];

        $suppliers = $this->withStats(Supplierproduct::query())
            ->with('store:id,name')
            ->whereIn('store_id', $storeIds)
            ->when($validated['search'] ?? null, fn ($q, $s) => $q->where(fn ($sub) => $sub
                ->where('name', 'ILIKE', "%{$s}%")
                ->orWhere('phone_one', 'ILIKE', "%{$s}%")
                ->orWhere('phone_two', 'ILIKE', "%{$s}%")
                ->orWhere('email', 'ILIKE', "%{$s}%")))
            ->when($validated['sort'] ?? 'name', fn ($q, $sort) => match ($sort) {
                'purchases' => $q->orderByRaw('received_total DESC NULLS LAST'),
                'recent' => $q->orderByRaw('last_supply_at DESC NULLS LAST'),
                default => $q,
            })
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $suppliers->map(fn (Supplierproduct $s) => $this->present($s)),
            'summary' => [
                'count' => $suppliers->count(),
                // Fournisseurs livrés dans les 90 derniers jours
                'active' => $suppliers->filter(fn ($s) => $s->last_supply_at && CarbonImmutable::parse($s->last_supply_at)->gte(now()->subDays(90)))->count(),
                'received_total' => (float) $suppliers->sum('received_total'),
                'pending_count' => (int) $suppliers->sum('pending_count'),
                'pending_amount' => (float) $suppliers->sum('pending_amount'),
            ],
            'currency' => config('subscriptions.default_currency', 'XOF'),
        ]);
    }

    /** Fiche fournisseur : contact, chiffres clés, derniers approvisionnements, produits achetés. */
    public function show(Request $request, $id)
    {
        $supplier = $this->find($request, $id, true);

        $recent = Supply::where('supplier_id', $supplier->id)
            ->withCount('supplyLineItems')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $received = Supply::where('supplier_id', $supplier->id)->where('status', 'received');
        $firstSupply = (clone $received)->min('created_at');

        $topProducts = DB::table('supply_line_items')
            ->join('supplies', 'supplies.id', '=', 'supply_line_items.supply_id')
            ->join('products', 'products.id', '=', 'supply_line_items.product_id')
            ->where('supplies.supplier_id', $supplier->id)
            ->where('supplies.status', 'received')
            ->whereNull('supplies.deleted_at')
            ->whereNull('supply_line_items.deleted_at')
            ->groupBy('products.id', 'products.name', 'products.base_unit')
            ->selectRaw('products.id, products.name, products.base_unit, SUM(supply_line_items.quantity) AS qty,
                SUM(supply_line_items.quantity * supply_line_items.purchase_price) AS total,
                MAX(supplies.created_at) AS last_at')
            ->orderByDesc('total')
            ->limit(8)
            ->get();

        return response()->json([
            'data' => [
                ...$this->present($supplier),
                'first_supply_at' => $firstSupply ? CarbonImmutable::parse($firstSupply)->toIso8601String() : null,
                'average_amount' => $supplier->received_count ? round($supplier->received_total / $supplier->received_count) : 0,
                'recent_supplies' => $recent->map(fn (Supply $s) => [
                    'id' => $s->id,
                    'order_number' => $s->order_number,
                    'date' => $s->created_at?->toIso8601String(),
                    'status' => $s->status,
                    'total_amount' => (float) $s->total_amount,
                    'lines_count' => (int) $s->supply_line_items_count,
                ]),
                'top_products' => $topProducts->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'unit' => $r->base_unit,
                    'quantity' => (float) $r->qty,
                    'total' => (float) $r->total,
                    'last_at' => CarbonImmutable::parse($r->last_at)->toIso8601String(),
                ]),
            ],
            'currency' => config('subscriptions.default_currency', 'XOF'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);
        $store = $this->ownedStore($request, $validated['store_id']);
        $this->assertUniqueName($store, $validated['name']);

        $supplier = Supplierproduct::create($this->attributes($validated) + ['store_id' => $store->id]);

        return response()->json([
            'success' => true,
            'message' => 'Fournisseur « ' . $supplier->name . ' » ajouté.',
            'data' => $this->present($this->withStats(Supplierproduct::query())->with('store:id,name')->find($supplier->id)),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $supplier = $this->find($request, $id);
        $validated = $this->validatePayload($request, $supplier);
        $this->assertUniqueName($supplier->store, $validated['name'], $supplier->id);

        $supplier->update($this->attributes($validated));

        return response()->json([
            'success' => true,
            'message' => 'Fournisseur « ' . $supplier->name . ' » mis à jour.',
            'data' => $this->present($this->find($request, $supplier->id, true)),
        ]);
    }

    /**
     * Suppression (corbeille) : l'historique des approvisionnements garde le nom du fournisseur.
     * Refusée tant qu'une livraison est en attente de réception.
     */
    public function destroy(Request $request, $id)
    {
        $supplier = $this->find($request, $id);

        $pending = Supply::where('supplier_id', $supplier->id)->where('status', 'pending')->count();
        if ($pending > 0) {
            throw ValidationException::withMessages([
                'supplier' => "« {$supplier->name} » a {$pending} approvisionnement(s) en attente : réceptionnez-les ou annulez-les avant de supprimer ce fournisseur.",
            ]);
        }

        $supplier->delete();

        return response()->json([
            'success' => true,
            'message' => 'Fournisseur « ' . $supplier->name . ' » supprimé. Ses approvisionnements passés restent consultables.',
        ]);
    }

    /** Statistiques d'achat calculées en sous-requêtes (une seule requête pour la liste). */
    private function withStats($query)
    {
        $received = fn ($q) => $q->where('status', 'received');
        $pending = fn ($q) => $q->where('status', 'pending');

        return $query
            ->withCount(['supplies as received_count' => $received, 'supplies as pending_count' => $pending])
            ->withSum(['supplies as received_total' => $received], 'total_amount')
            ->withSum(['supplies as pending_amount' => $pending], 'total_amount')
            ->withMax(['supplies as last_supply_at' => $received], 'created_at');
    }

    /** Fournisseur d'une boutique de l'entreprise, sinon 404. */
    private function find(Request $request, $id, bool $withStats = false): Supplierproduct
    {
        $storeIds = $this->scope->stores($request->user())->pluck('id');
        $query = $withStats ? $this->withStats(Supplierproduct::query()) : Supplierproduct::query();

        return $query->with('store:id,name')->whereIn('store_id', $storeIds)->findOrFail($id);
    }

    private function ownedStore(Request $request, $storeId): Store
    {
        $store = $this->scope->stores($request->user())->firstWhere('id', (int) $storeId);
        abort_if(!$store, 422, 'La boutique choisie ne fait pas partie de vos boutiques.');

        return $store;
    }

    private function assertUniqueName(Store $store, string $name, ?int $ignoreId = null): void
    {
        $exists = Supplierproduct::where('store_id', $store->id)
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($name))])
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['name' => 'Un fournisseur porte déjà ce nom dans la boutique « ' . $store->name . ' ».']);
        }
    }

    /** La boutique n'est choisie qu'à la création : ses approvisionnements en dépendent. */
    private function validatePayload(Request $request, ?Supplierproduct $supplier = null): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'phone_one' => 'required|string|max:20',
            'phone_two' => 'nullable|string|max:20',
            'email' => [
                'nullable', 'email', 'max:255',
                Rule::unique('supplierproducts', 'email')->whereNull('deleted_at')->ignore($supplier?->id),
            ],
            'address' => 'nullable|string|max:255',
        ];
        if (!$supplier) {
            $rules['store_id'] = 'required|integer';
        }

        return $request->validate($rules, [
            'store_id.required' => 'La boutique est obligatoire.',
            'name.required' => 'Le nom du fournisseur est obligatoire.',
            'phone_one.required' => 'Le téléphone est obligatoire.',
            'phone_one.max' => 'Le téléphone ne doit pas dépasser 20 caractères.',
            'phone_two.max' => 'Le second téléphone ne doit pas dépasser 20 caractères.',
            'email.email' => 'L\'adresse e-mail n\'est pas valide.',
            'email.unique' => 'Cette adresse e-mail est déjà utilisée par un autre fournisseur.',
        ]);
    }

    private function attributes(array $validated): array
    {
        return [
            'name' => trim($validated['name']),
            'phone_one' => trim($validated['phone_one']),
            'phone_two' => isset($validated['phone_two']) ? (trim($validated['phone_two']) ?: null) : null,
            'email' => isset($validated['email']) ? (trim($validated['email']) ?: null) : null,
            // Colonne non nulle : une adresse vide reste possible
            'address' => trim($validated['address'] ?? ''),
        ];
    }

    private function present(Supplierproduct $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'phone' => $s->phone_one,
            'phone_two' => $s->phone_two,
            'email' => $s->email,
            'address' => $s->address,
            'store' => $s->store ? ['id' => $s->store->id, 'name' => $s->store->name] : null,
            'received_count' => (int) ($s->received_count ?? 0),
            'received_total' => (float) ($s->received_total ?? 0),
            'pending_count' => (int) ($s->pending_count ?? 0),
            'pending_amount' => (float) ($s->pending_amount ?? 0),
            'last_supply_at' => $s->last_supply_at ? CarbonImmutable::parse($s->last_supply_at)->toIso8601String() : null,
        ];
    }
}
