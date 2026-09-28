<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SerialNumber;
use App\Models\Store;
use App\Models\Supplierproduct;
use App\Models\Supply;
use App\Models\SupplyLineItem;
use App\Services\OwnerScopeService;
use App\Services\SupplyService;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approvisionnements des boutiques du propriétaire : suivi des achats, saisie,
 * réception (entrée en stock) et annulation. Inclut les produits à commander
 * nécessaires au formulaire (les fournisseurs : OwnerSupplierController).
 */
class OwnerSupplyController extends Controller
{

    public function __construct(protected OwnerScopeService $scope, protected SupplyService $supplies)
    {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'start' => 'required|date',
            'end' => 'required|date|after_or_equal:start',
            'status' => 'nullable|in:pending,received,cancelled',
            'supplier_id' => 'nullable|integer',
            'search' => 'nullable|string|max:100',
            'sort' => 'nullable|in:recent,amount',
            'perPage' => 'nullable|integer|min:1|max:100',
        ]);

        $scope = $this->scope->resolve($request);
        $storeIds = $scope['store_ids'];
        $start = CarbonImmutable::parse($validated['start'])->startOfDay();
        $end = CarbonImmutable::parse($validated['end'])->startOfDay();
        $days = (int) $start->diffInDays($end) + 1;
        $previousEnd = $start->subDay();
        $previousStart = $previousEnd->subDays($days - 1);
        $granularity = $days <= 45 ? 'day' : ($days <= 190 ? 'week' : 'month');

        $search = $validated['search'] ?? null;
        $supplierId = $validated['supplier_id'] ?? null;

        // Période + recherche + fournisseur (le statut ne filtre que la liste)
        $base = fn (CarbonImmutable $from, CarbonImmutable $to) => Supply::query()
            ->whereIn('supplies.store_id', $storeIds)
            ->whereBetween('supplies.created_at', [$from, $to->endOfDay()])
            ->when($supplierId, fn ($q, $id) => $q->where('supplies.supplier_id', $id))
            ->when($search, fn ($q, $s) => $q->where(fn ($sub) => $sub
                ->where('supplies.order_number', 'ILIKE', "%{$s}%")
                ->orWhereHas('supplier', fn ($sp) => $sp->withTrashed()->where('name', 'ILIKE', "%{$s}%"))));

        $byStatus = $base($start, $end)
            ->selectRaw('supplies.status, COUNT(*) AS n, COALESCE(SUM(supplies.total_amount), 0) AS total')
            ->groupBy('supplies.status')
            ->get()
            ->keyBy('status');
        $previous = $base($previousStart, $previousEnd)
            ->where('supplies.status', 'received')
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(supplies.total_amount), 0) AS total')
            ->first();

        // Reste à réceptionner : tout ce qui est en attente, quelle que soit la date
        $pending = Supply::whereIn('store_id', $storeIds)
            ->where('status', 'pending')
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total_amount), 0) AS total, MIN(created_at) AS oldest')
            ->first();

        $supplies = $base($start, $end)
            ->with($this->listRelations())
            ->withCount('supplyLineItems')
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('supplies.status', $status))
            ->when(($validated['sort'] ?? 'recent') === 'amount',
                fn ($q) => $q->orderByDesc('supplies.total_amount'),
                fn ($q) => $q->orderByDesc('supplies.created_at'))
            ->orderByDesc('supplies.id')
            ->paginate($validated['perPage'] ?? 20);

        $count = fn (string $status) => (int) ($byStatus[$status]->n ?? 0);
        $amount = fn (string $status) => (float) ($byStatus[$status]->total ?? 0);

        return response()->json([
            'data' => collect($supplies->items())->map(fn (Supply $s) => $this->present($s)),
            'meta' => [
                'current_page' => $supplies->currentPage(),
                'per_page' => $supplies->perPage(),
                'total' => $supplies->total(),
                'last_page' => $supplies->lastPage(),
            ],
            'summary' => [
                'received' => ['value' => $amount('received'), 'previous' => (float) $previous->total],
                'received_count' => ['value' => $count('received'), 'previous' => (int) $previous->n],
                'pending' => [
                    'count' => (int) $pending->n,
                    'amount' => (float) $pending->total,
                    'oldest' => $pending->oldest ? CarbonImmutable::parse($pending->oldest)->toIso8601String() : null,
                ],
                'status_counts' => [
                    'all' => $count('pending') + $count('received') + $count('cancelled'),
                    'pending' => $count('pending'),
                    'received' => $count('received'),
                    'cancelled' => $count('cancelled'),
                ],
                'cancelled_amount' => $amount('cancelled'),
                'by_supplier' => $this->bySupplier($base($start, $end)),
                'top_products' => $this->topProducts($base($start, $end)),
            ],
            'series' => $this->series($base($start, $end), $start, $end, $granularity),
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'days' => $days,
                'previous_start' => $previousStart->toDateString(),
                'previous_end' => $previousEnd->toDateString(),
                'granularity' => $granularity,
            ],
            'currency' => config('subscriptions.default_currency', 'XOF'),
            'excluded_stores' => $scope['excluded'],
        ]);
    }

    public function show(Request $request, $id)
    {
        return response()->json([
            'data' => $this->presentDetail($this->find($request, $id)),
            'currency' => config('subscriptions.default_currency', 'XOF'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request, true);
        $store = $this->ownedStore($request, $validated['store_id']);
        $this->assertSupplier($store, $validated['supplier_id']);

        $userId = $request->user()->id;
        $supply = DB::transaction(function () use ($store, $validated, $userId) {
            $supply = $this->supplies->create($store, $validated['supplier_id'], $validated['line_items'], $userId);

            return !empty($validated['receive']) ? $this->supplies->receive($supply, $userId) : $supply;
        });

        return response()->json([
            'success' => true,
            'message' => !empty($validated['receive'])
                ? "Approvisionnement {$supply->order_number} réceptionné : le stock est à jour."
                : "Approvisionnement {$supply->order_number} enregistré en attente de réception.",
            'data' => $this->presentDetail($supply->fresh()),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $supply = $this->find($request, $id);
        $validated = $this->validatePayload($request, false);
        $this->assertSupplier($supply->store, $validated['supplier_id']);

        $userId = $request->user()->id;
        $supply = DB::transaction(function () use ($supply, $validated, $userId) {
            $supply = $this->supplies->update($supply, $validated['supplier_id'], $validated['line_items'], $userId);

            return !empty($validated['receive']) ? $this->supplies->receive($supply, $userId) : $supply;
        });

        return response()->json([
            'success' => true,
            'message' => !empty($validated['receive'])
                ? "Approvisionnement {$supply->order_number} mis à jour et réceptionné."
                : "Approvisionnement {$supply->order_number} mis à jour.",
            'data' => $this->presentDetail($supply->fresh()),
        ]);
    }

    public function receive(Request $request, $id)
    {
        $supply = $this->supplies->receive($this->find($request, $id), $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => "Approvisionnement {$supply->order_number} réceptionné : le stock est à jour.",
            'data' => $this->presentDetail($supply->fresh()),
        ]);
    }

    public function cancel(Request $request, $id)
    {
        $supply = $this->supplies->cancel($this->find($request, $id));

        return response()->json([
            'success' => true,
            'message' => "Approvisionnement {$supply->order_number} annulé.",
            'data' => $this->presentDetail($supply->fresh()),
        ]);
    }

    /**
     * Contrôle au scan : parmi les numéros de série donnés, ceux déjà enregistrés dans la boutique
     * (en stock, vendus ou sur une autre livraison). `supply_id` : approvisionnement en cours de modification.
     */
    public function checkSerials(Request $request)
    {
        $validated = $request->validate([
            'store_id' => 'required|integer',
            'supply_id' => 'nullable|integer',
            'serials' => 'required|array|min:1|max:500',
            'serials.*' => 'required|string|max:100',
        ]);
        $store = $this->ownedStore($request, $validated['store_id']);
        $excludeId = !empty($validated['supply_id']) ? $this->find($request, $validated['supply_id'])->id : null;

        $conflicts = $this->supplies->serialConflicts($store, $validated['serials'], $excludeId);

        return response()->json([
            'data' => $conflicts->map(function (SerialNumber $s) {
                $supply = $s->supplyLineItem?->supply;

                return [
                    'serial_number' => $s->serial_number,
                    'product_id' => $s->product_id,
                    'product' => $s->product?->name,
                    'status' => $s->is_sold ? 'sold' : ($supply && $supply->status === 'pending' ? 'pending' : 'in_stock'),
                    'order_number' => $supply?->order_number,
                ];
            })->values(),
        ]);
    }

    /**
     * Produits d'une boutique à approvisionner, avec leur dernier prix d'achat.
     * `restock=1` : uniquement les produits en rupture ou sous le seuil d'alerte.
     */
    public function products(Request $request)
    {
        $validated = $request->validate([
            'store_id' => 'required|integer',
            'search' => 'nullable|string|max:100',
            'restock' => 'nullable|boolean',
        ]);
        $store = $this->ownedStore($request, $validated['store_id']);
        $restock = $request->boolean('restock');

        $lastPrice = SupplyLineItem::query()
            ->select('supply_line_items.purchase_price')
            ->join('supplies', 'supplies.id', '=', 'supply_line_items.supply_id')
            ->whereColumn('supply_line_items.product_id', 'products.id')
            ->where('supplies.status', '!=', 'cancelled')
            ->whereNull('supplies.deleted_at')
            ->orderByDesc('supplies.created_at')
            ->limit(1);

        $products = Product::query()
            ->select('products.*')
            ->addSelect(['last_purchase_price' => $lastPrice])
            ->where('store_id', $store->id)
            ->when($validated['search'] ?? null, fn ($q, $s) => $q->where('name', 'ILIKE', "%{$s}%"))
            ->when($restock, fn ($q) => $q->whereColumn('base_unit_quantity', '<=', 'alert_threshold'))
            ->when($restock, fn ($q) => $q->orderBy('base_unit_quantity'), fn ($q) => $q->orderBy('name'))
            ->limit($restock ? 100 : 25)
            ->get();

        return response()->json([
            'data' => $products->map(function (Product $p) {
                $quantity = (float) $p->base_unit_quantity;

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'unit' => $p->base_unit,
                    'quantity' => $quantity,
                    'alert_threshold' => (int) $p->alert_threshold,
                    'stock_state' => $quantity <= 0 ? 'out' : ($quantity <= $p->alert_threshold ? 'low' : 'ok'),
                    'require_serial_number' => (bool) $p->require_serial_number,
                    'last_purchase_price' => $p->last_purchase_price !== null ? (float) $p->last_purchase_price : null,
                ];
            }),
            'uses_measurements' => (bool) ($store->uses_measurements ?? true),
        ]);
    }

    private function bySupplier(Builder $query): array
    {
        return $query->where('supplies.status', 'received')
            ->join('supplierproducts', 'supplierproducts.id', '=', 'supplies.supplier_id')
            ->groupBy('supplierproducts.id', 'supplierproducts.name')
            ->selectRaw('supplierproducts.id, supplierproducts.name, COUNT(*) AS n, SUM(supplies.total_amount) AS total')
            ->orderByDesc('total')
            ->limit(6)
            ->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'count' => (int) $r->n, 'total' => (float) $r->total])
            ->all();
    }

    private function topProducts(Builder $query): array
    {
        return $query->where('supplies.status', 'received')
            ->join('supply_line_items', 'supply_line_items.supply_id', '=', 'supplies.id')
            ->join('products', 'products.id', '=', 'supply_line_items.product_id')
            ->whereNull('supply_line_items.deleted_at')
            ->groupBy('products.id', 'products.name', 'products.base_unit')
            ->selectRaw('products.id, products.name, products.base_unit, SUM(supply_line_items.quantity) AS qty,
                SUM(supply_line_items.quantity * supply_line_items.purchase_price) AS total')
            ->orderByDesc('total')
            ->limit(6)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'unit' => $r->base_unit,
                'quantity' => (float) $r->qty,
                'total' => (float) $r->total,
            ])
            ->all();
    }

    /** Montants reçus par intervalle (date de l'approvisionnement). */
    private function series(Builder $query, CarbonImmutable $start, CarbonImmutable $end, string $granularity): array
    {
        $rows = $query->where('supplies.status', 'received')
            ->selectRaw("DATE(DATE_TRUNC('{$granularity}', supplies.created_at)) AS bucket, SUM(supplies.total_amount) AS value")
            ->groupBy('bucket')
            ->pluck('value', 'bucket');

        $cursor = match ($granularity) {
            'week' => $start->startOfWeek(),
            'month' => $start->startOfMonth(),
            default => $start,
        };
        $step = ['day' => '1 day', 'week' => '1 week', 'month' => '1 month'][$granularity];

        $points = [];
        foreach (CarbonPeriod::create($cursor, $step, $end) as $bucket) {
            $key = $bucket->toDateString();
            $points[] = ['bucket' => $key, 'value' => (float) ($rows[$key] ?? 0)];
        }

        return $points;
    }

    /** Relations de la liste : le fournisseur reste affiché même s'il a été supprimé depuis. */
    private function listRelations(): array
    {
        return [
            'store:id,name',
            'supplier' => fn ($q) => $q->withTrashed()->select('id', 'name', 'phone_one'),
            'user:id,first_name,last_name',
        ];
    }

    /** Approvisionnement d'une boutique de l'entreprise, sinon 404. */
    private function find(Request $request, $id): Supply
    {
        $storeIds = $this->scope->stores($request->user())->pluck('id');

        return Supply::whereIn('store_id', $storeIds)->findOrFail($id);
    }

    private function ownedStore(Request $request, $storeId): Store
    {
        $store = $this->scope->stores($request->user())->firstWhere('id', (int) $storeId);
        abort_if(!$store, 422, 'La boutique choisie ne fait pas partie de vos boutiques.');

        return $store;
    }

    private function assertSupplier(Store $store, $supplierId): void
    {
        if (!Supplierproduct::where('store_id', $store->id)->whereKey($supplierId)->exists()) {
            throw ValidationException::withMessages([
                'supplier_id' => 'Ce fournisseur n\'est pas rattaché à la boutique « ' . $store->name . ' ».',
            ]);
        }
    }

    private function validatePayload(Request $request, bool $creating): array
    {
        // La boutique n'est choisie qu'à la création (produits et fournisseur en dépendent)
        $storeRule = $creating ? ['store_id' => 'required|integer'] : [];

        return $request->validate($storeRule + [
            'supplier_id' => 'required|integer',
            'receive' => 'nullable|boolean',
            'line_items' => 'required|array|min:1|max:200',
            'line_items.*.product_id' => 'required|integer|distinct',
            'line_items.*.quantity' => 'required|numeric|min:0.001|max:9999999',
            'line_items.*.purchase_price' => 'required|numeric|min:0|max:999999999',
            'line_items.*.serial_numbers' => 'nullable|array',
            'line_items.*.serial_numbers.*' => 'nullable|string|max:100',
        ], [
            'store_id.required' => 'La boutique est obligatoire.',
            'supplier_id.required' => 'Le fournisseur est obligatoire.',
            'line_items.required' => 'Ajoutez au moins un produit.',
            'line_items.min' => 'Ajoutez au moins un produit.',
            'line_items.*.product_id.distinct' => 'Un même produit apparaît sur plusieurs lignes.',
            'line_items.*.quantity.required' => 'La quantité est obligatoire sur chaque ligne.',
            'line_items.*.quantity.min' => 'Chaque quantité doit être supérieure à 0.',
            'line_items.*.purchase_price.required' => 'Le prix d\'achat est obligatoire sur chaque ligne.',
            'line_items.*.purchase_price.min' => 'Le prix d\'achat ne peut pas être négatif.',
        ]);
    }

    private function person($user): ?string
    {
        return $user ? trim($user->first_name . ' ' . $user->last_name) : null;
    }

    private function present(Supply $s): array
    {
        return [
            'id' => $s->id,
            'order_number' => $s->order_number,
            'date' => $s->created_at?->toIso8601String(),
            'status' => $s->status,
            'total_amount' => (float) $s->total_amount,
            'lines_count' => (int) ($s->supply_line_items_count ?? $s->supplyLineItems()->count()),
            'store' => $s->store ? ['id' => $s->store->id, 'name' => $s->store->name] : null,
            'supplier' => $s->supplier ? ['id' => $s->supplier->id, 'name' => $s->supplier->name, 'phone' => $s->supplier->phone_one] : null,
            'user' => $this->person($s->user),
            'received_at' => $s->received_at?->toIso8601String(),
        ];
    }

    private function presentDetail(Supply $s): array
    {
        $s->load([
            'store:id,name,uses_measurements',
            'supplier' => fn ($q) => $q->withTrashed(),
            'user:id,first_name,last_name',
            'receiver:id,first_name,last_name',
            'supplyLineItems' => fn ($q) => $q->orderBy('id'),
            'supplyLineItems.product' => fn ($q) => $q->withTrashed(),
            'supplyLineItems.serialNumbers',
        ]);

        return [
            ...$this->present($s),
            'supplier' => $s->supplier ? [
                'id' => $s->supplier->id,
                'name' => $s->supplier->name,
                'phone' => $s->supplier->phone_one,
                'phone_two' => $s->supplier->phone_two,
                'email' => $s->supplier->email,
                'address' => $s->supplier->address,
            ] : null,
            'uses_measurements' => (bool) ($s->store?->uses_measurements ?? true),
            'received_by' => $this->person($s->receiver),
            'updated_at' => $s->updated_at?->toIso8601String(),
            'lines' => $s->supplyLineItems->map(fn (SupplyLineItem $line) => [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'product' => $line->product?->name ?? 'Produit supprimé',
                'product_deleted' => !$line->product || $line->product->trashed(),
                'unit' => $line->product?->base_unit,
                'require_serial_number' => (bool) $line->product?->require_serial_number,
                'current_stock' => $line->product ? (float) $line->product->base_unit_quantity : null,
                'quantity' => (float) $line->quantity,
                'purchase_price' => (float) $line->purchase_price,
                'total' => round((float) $line->quantity * (float) $line->purchase_price),
                'serial_numbers' => $line->serialNumbers->pluck('serial_number')->values(),
            ])->values(),
        ];
    }
}
