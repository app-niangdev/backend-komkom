<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\OwnerScopeService;
use App\Services\SaleService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Factures & reçus : suivi des factures de la période, reste à encaisser, encaissement
 * d'un paiement et données d'impression (facture A4 ou ticket de caisse).
 */
class OwnerInvoiceController extends Controller
{
    public function __construct(protected OwnerScopeService $scope, protected SaleService $sales)
    {
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'start' => 'required|date',
            'end' => 'required|date|after_or_equal:start',
            'status' => 'nullable|in:due,no_paid,partial,paid,cancelled',
            'search' => 'nullable|string|max:100',
            'perPage' => 'nullable|integer|min:1|max:100',
        ]);
        $scope = $this->scope->resolve($request);
        $storeIds = $scope['store_ids'];
        $sellerId = $this->scope->sellerId($request->user());
        $start = CarbonImmutable::parse($filters['start'])->startOfDay();
        $end = CarbonImmutable::parse($filters['end'])->endOfDay();

        $period = fn () => $this->baseQuery($storeIds, $filters['search'] ?? null, $sellerId)
            ->whereBetween('invoices.created_at', [$start, $end]);

        $list = $period()
            ->when($filters['status'] ?? null, fn ($q, $s) => match ($s) {
                'due' => $q->where('is_cancelled', false)->where('balance', '>', 0),
                'cancelled' => $q->where(fn ($c) => $c->where('is_cancelled', true)->orWhere('invoice_status', 'cancelled')),
                default => $q->where('is_cancelled', false)->where('invoice_status', $s),
            })
            ->with(['store:id,name', 'customer:id,name,phone', 'sale:id,sale_number,seller_id,status', 'sale.seller:id,first_name,last_name'])
            ->orderByDesc('invoices.created_at')
            ->orderByDesc('invoices.id')
            ->paginate($filters['perPage'] ?? 15);

        $figures = (clone $period())
            ->where('is_cancelled', false)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(amount_total), 0) AS invoiced, COALESCE(SUM(amount_paid), 0) AS paid')
            ->first();

        // Reste à encaisser, toutes périodes confondues : ce que les clients doivent aujourd'hui
        $open = Invoice::whereIn('store_id', $storeIds)
            ->when($sellerId, fn ($q, $id) => $q->whereHas('sale', fn ($s) => $s->where('seller_id', $id)))
            ->where('is_cancelled', false)
            ->where('balance', '>', 0)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(balance), 0) AS due')
            ->first();

        $collected = DB::table('payment_receipts')
            ->join('invoices', 'invoices.id', '=', 'payment_receipts.invoice_id')
            ->whereIn('invoices.store_id', $storeIds)
            ->when($sellerId, fn ($q, $id) => $q->whereIn('invoices.sale_id', DB::table('sales')->where('seller_id', $id)->select('id')))
            ->whereNull('payment_receipts.deleted_at')
            ->where('invoices.is_cancelled', false)
            ->whereBetween('payment_receipts.date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('payment_receipts.payment_type')
            ->selectRaw('payment_receipts.payment_type AS type, SUM(payment_receipts.amount) AS total')
            ->pluck('total', 'type');

        return response()->json([
            'data' => collect($list->items())->map(fn (Invoice $i) => $this->summary($i)),
            'meta' => [
                'current_page' => $list->currentPage(),
                'per_page' => $list->perPage(),
                'total' => $list->total(),
                'last_page' => $list->lastPage(),
            ],
            'summary' => [
                'invoiced' => (float) $figures->invoiced,
                'invoices_count' => (int) $figures->n,
                'invoiced_paid' => (float) $figures->paid,
                'collected' => (float) $collected->sum(),
                'collected_by_type' => $collected->map(fn ($v) => (float) $v),
                'outstanding' => (float) $open->due,
                'open_count' => (int) $open->n,
            ],
            'currency' => config('subscriptions.default_currency', 'XOF'),
            'excluded_stores' => $scope['excluded'],
        ]);
    }

    public function show(Request $request, $id)
    {
        $invoice = $this->find($request, $id)->load([
            'store.company',
            'customer',
            'sale.seller:id,first_name,last_name',
            'sale.saleLineItems.product:id,name,base_unit',
            'sale.saleLineItems.serialNumbers',
            'paymentReceipts' => fn ($q) => $q->orderBy('date')->orderBy('id'),
            'paymentReceipts.user:id,first_name,last_name',
        ]);
        $sale = $invoice->sale;
        $store = $invoice->store;

        return response()->json([
            'data' => $this->summary($invoice) + [
                'customer_phone' => $invoice->customer?->phone,
                'sale' => [
                    'id' => $sale?->id,
                    'number' => $sale?->sale_number,
                    'date' => $sale?->created_at?->toIso8601String(),
                    'status' => $sale?->status,
                    'gross_amount' => (float) ($sale?->gross_amount ?? $invoice->amount_total),
                    'discount' => (float) ($sale?->discount ?? 0),
                ],
                'items' => ($sale?->saleLineItems ?? collect())->map(fn ($item) => [
                    'id' => $item->id,
                    'product' => $item->product?->name ?? 'Produit supprimé',
                    'unit' => $item->unit_name ?? $item->product?->base_unit,
                    'quantity' => (float) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'subtotal' => (float) $item->subtotal,
                    'serial_numbers' => $item->serialNumbers->pluck('serial_number')->filter()->values(),
                ])->values(),
                'payments' => $invoice->paymentReceipts->map(fn ($p) => [
                    'id' => $p->id,
                    'date' => (string) $p->date?->toDateString(),
                    'recorded_at' => $p->created_at?->toIso8601String(),
                    'amount' => (float) $p->amount,
                    'type' => $p->payment_type,
                    'user' => $p->user ? trim($p->user->first_name . ' ' . $p->user->last_name) : null,
                ])->values(),
                // En-tête des documents imprimés
                'issuer' => [
                    'name' => $store?->name,
                    'company' => $store?->company?->name,
                    'slogan' => $store?->slogan ?: $store?->company?->slogan,
                    'address' => $store?->address,
                    'phones' => array_values(array_filter([$store?->phone_one, $store?->phone_two, $store?->phone_three])),
                    'email' => $store?->email,
                    'logo_url' => $store?->logo_url,
                    'color' => $store?->effective_primary_color,
                    // Rouleau de l'imprimante ticket de la boutique (mm)
                    'ticket_width' => (int) ($store?->ticket_width ?? 80),
                ],
            ],
            'currency' => config('subscriptions.default_currency', 'XOF'),
        ]);
    }

    public function pay(Request $request, $id)
    {
        $invoice = $this->find($request, $id);
        $validated = $request->validate([
            'amount' => 'required|integer|min:1',
            'payment_type' => ['required', Rule::in(SaleService::PAYMENT_TYPES)],
            'date' => 'required|date|before_or_equal:today',
        ], [
            'amount.required' => 'Saisissez le montant reçu.',
            'amount.min' => 'Le montant doit être supérieur à zéro.',
            'date.before_or_equal' => 'La date du paiement ne peut pas être dans le futur.',
        ]);

        $invoice = $this->sales->recordPayment(
            $invoice,
            (int) $validated['amount'],
            $validated['payment_type'],
            $validated['date'],
            $request->user()
        );

        return response()->json([
            'message' => $invoice->balance > 0
                ? 'Paiement enregistré. Reste à payer : ' . number_format($invoice->balance, 0, ',', ' ') . '.'
                : 'Paiement enregistré : la facture est soldée.',
            'data' => [
                'id' => $invoice->id,
                'amount_paid' => (float) $invoice->amount_paid,
                'balance' => (float) $invoice->balance,
                'status' => $invoice->invoice_status,
            ],
        ], 201);
    }

    /** Facture d'une boutique accessible du périmètre (d'une vente du vendeur connecté), sinon 404. */
    private function find(Request $request, $id): Invoice
    {
        $storeIds = $this->scope->resolve($request->merge(['store_id' => null]))['store_ids'];

        return $this->baseQuery($storeIds, null, $this->scope->sellerId($request->user()))->findOrFail($id);
    }

    private function baseQuery(array $storeIds, ?string $search, ?int $sellerId = null): Builder
    {
        return Invoice::query()
            ->whereIn('invoices.store_id', $storeIds)
            ->when($sellerId, fn ($q, $id) => $q->whereHas('sale', fn ($s) => $s->where('seller_id', $id)))
            ->when($search, fn ($q, $s) => $q->where(fn ($sub) => $sub
                ->where('invoice_number', 'ILIKE', "%{$s}%")
                ->orWhere('customer_name', 'ILIKE', "%{$s}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'ILIKE', "%{$s}%")->orWhere('phone', 'ILIKE', "%{$s}%"))
                ->orWhereHas('sale', fn ($v) => $v->where('sale_number', 'ILIKE', "%{$s}%"))));
    }

    private function summary(Invoice $invoice): array
    {
        $cancelled = $invoice->is_cancelled || $invoice->invoice_status === 'cancelled';

        return [
            'id' => $invoice->id,
            'number' => $invoice->invoice_number,
            'date' => $invoice->created_at?->toIso8601String(),
            'store' => $invoice->store ? ['id' => $invoice->store->id, 'name' => $invoice->store->name] : null,
            'customer' => $invoice->customer?->name ?? ($invoice->customer_name && $invoice->customer_name !== 'Anonyme' ? $invoice->customer_name : 'Client anonyme'),
            'customer_id' => $invoice->customer_id,
            'sale_number' => $invoice->sale?->sale_number,
            'seller' => $invoice->sale?->seller ? trim($invoice->sale->seller->first_name . ' ' . $invoice->sale->seller->last_name) : null,
            'amount_total' => (float) $invoice->amount_total,
            'amount_paid' => $cancelled ? 0.0 : (float) $invoice->amount_paid,
            'balance' => $cancelled ? 0.0 : (float) $invoice->balance,
            'status' => $cancelled ? 'cancelled' : $invoice->invoice_status,
        ];
    }
}
