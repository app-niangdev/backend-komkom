<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\SerialNumber;
use App\Services\OwnerScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Numéros de série / IMEI d'une boutique : traçabilité de chaque appareil (entrée par
 * approvisionnement, vente) et correction d'un numéro mal saisi.
 */
class OwnerSerialController extends Controller
{
    public function __construct(protected OwnerScopeService $scope)
    {
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:100',
            'status' => 'nullable|in:in_stock,sold,pending',
            'product_id' => 'nullable|integer',
            'perPage' => 'nullable|integer|min:1|max:100',
        ]);
        $scope = $this->scope->resolve($request);
        $base = fn () => $this->baseQuery($scope['store_ids'], $filters['search'] ?? null, $filters['product_id'] ?? null);

        $list = $base()
            ->when($filters['status'] ?? null, fn ($q, $status) => $this->applyStatus($q, $status))
            ->with([
                'product' => fn ($q) => $q->withTrashed()->select('id', 'name', 'store_id'),
                'product.store:id,name',
                'supplyLineItem.supply:id,order_number,status,received_at,created_at,supplier_id',
                'supplyLineItem.supply.supplier' => fn ($q) => $q->withTrashed()->select('id', 'name'),
                'saleLineItem.sale:id,sale_number,created_at,customer_id,status',
                'saleLineItem.sale.customer:id,name',
            ])
            ->orderByDesc('serial_numbers.created_at')
            ->orderByDesc('serial_numbers.id')
            ->paginate($filters['perPage'] ?? 20);

        return response()->json([
            'data' => collect($list->items())->map(fn (SerialNumber $s) => $this->payload($s)),
            'meta' => [
                'current_page' => $list->currentPage(),
                'per_page' => $list->perPage(),
                'total' => $list->total(),
                'last_page' => $list->lastPage(),
            ],
            'summary' => [
                'in_stock' => $this->applyStatus($base(), 'in_stock')->count(),
                'sold' => $this->applyStatus($base(), 'sold')->count(),
                'pending' => $this->applyStatus($base(), 'pending')->count(),
            ],
            'excluded_stores' => $scope['excluded'],
        ]);
    }

    /** Correction d'un numéro : unique dans la boutique ; ceux d'une livraison en attente se corrigent sur l'approvisionnement. */
    public function update(Request $request, $id)
    {
        $storeIds = $this->scope->resolve($request->merge(['store_id' => null]))['store_ids'];
        $serial = SerialNumber::with(['product', 'supplyLineItem.supply'])
            ->whereHas('product', fn ($q) => $q->withTrashed()->whereIn('store_id', $storeIds))
            ->findOrFail($id);

        $value = trim((string) $request->validate([
            'serial_number' => 'required|string|min:4|max:100',
        ], [
            'serial_number.required' => 'Le numéro de série est obligatoire.',
            'serial_number.min' => 'Le numéro de série doit contenir au moins 4 caractères.',
        ])['serial_number']);

        if ($serial->supplyLineItem?->supply && $serial->supplyLineItem->supply->status === 'pending') {
            throw ValidationException::withMessages([
                'serial_number' => 'Ce numéro fait partie d\'une livraison pas encore réceptionnée : corrigez-le depuis l\'approvisionnement '
                    . $serial->supplyLineItem->supply->order_number . '.',
            ]);
        }

        // Unicité dans la boutique (corbeille comprise : la contrainte unique porte aussi sur elle)
        $taken = SerialNumber::withTrashed()
            ->whereKeyNot($serial->id)
            ->whereRaw('LOWER(serial_number) = ?', [mb_strtolower($value)])
            ->whereHas('product', fn ($q) => $q->withTrashed()->where('store_id', $serial->product->store_id))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['serial_number' => "Le numéro « {$value} » est déjà enregistré dans cette boutique."]);
        }

        $serial->update(['serial_number' => $value]);

        return response()->json([
            'message' => 'Numéro de série corrigé.',
            'data' => $this->payload($serial->fresh(['product', 'product.store', 'supplyLineItem.supply.supplier', 'saleLineItem.sale.customer'])),
        ]);
    }

    private function baseQuery(array $storeIds, ?string $search, ?int $productId): Builder
    {
        return SerialNumber::query()
            ->whereHas('product', fn ($q) => $q->withTrashed()->whereIn('store_id', $storeIds))
            ->when($productId, fn ($q, $id) => $q->where('product_id', $id))
            ->when($search, fn ($q, $s) => $q->where(fn ($sub) => $sub
                ->where('serial_number', 'ILIKE', "%{$s}%")
                ->orWhereHas('product', fn ($p) => $p->withTrashed()->where('name', 'ILIKE', "%{$s}%"))));
    }

    /**
     * en stock : non vendu, livraison reçue (ou saisi hors approvisionnement) ;
     * vendu ; à réceptionner : saisi sur une livraison encore en attente.
     */
    private function applyStatus(Builder $query, string $status): Builder
    {
        return match ($status) {
            'in_stock' => $query->inStock(),
            'sold' => $query->where('is_sold', true),
            'pending' => $query->where('is_sold', false)
                ->whereHas('supplyLineItem.supply', fn ($s) => $s->where('status', 'pending')),
        };
    }

    private function payload(SerialNumber $s): array
    {
        $supply = $s->supplyLineItem?->supply;
        $sale = $s->saleLineItem?->sale;
        $status = $s->is_sold ? 'sold' : ($supply && $supply->status === 'pending' ? 'pending' : 'in_stock');

        return [
            'id' => $s->id,
            'serial_number' => $s->serial_number,
            'status' => $status,
            'product' => $s->product ? ['id' => $s->product->id, 'name' => $s->product->name] : null,
            'store' => $s->product?->store ? ['id' => $s->product->store->id, 'name' => $s->product->store->name] : null,
            'entered_at' => ($supply?->received_at ?? $supply?->created_at ?? $s->created_at)?->toIso8601String(),
            'supply' => $supply ? [
                'id' => $supply->id,
                'order_number' => $supply->order_number,
                'supplier' => $supply->supplier?->name,
            ] : null,
            'sale' => $sale ? [
                'id' => $sale->id,
                'number' => $sale->sale_number,
                'date' => $sale->created_at?->toIso8601String(),
                'customer' => $sale->customer?->name,
            ] : null,
        ];
    }
}
