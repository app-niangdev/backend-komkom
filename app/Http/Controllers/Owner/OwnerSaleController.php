<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Services\OwnerScopeService;
use App\Services\PdfReportService;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ventes des boutiques du propriétaire (consultation) : analyses de la période, liste, détail, export.
 */
class OwnerSaleController extends Controller
{
    public function __construct(protected OwnerScopeService $scope)
    {
    }

    public function index(Request $request)
    {
        $filters = $this->validateFilters($request);
        $scope = $this->scope->resolve($request);

        $start = CarbonImmutable::parse($filters['start'])->startOfDay();
        $end = CarbonImmutable::parse($filters['end'])->endOfDay();
        $days = (int) $start->diffInDays($end->startOfDay()) + 1;
        $previousEnd = $start->subDay()->endOfDay();
        $previousStart = $previousEnd->subDays($days - 1)->startOfDay();
        $granularity = $days <= 45 ? 'day' : ($days <= 190 ? 'week' : 'month');

        // Analyses : période + boutique + recherche (indépendantes des filtres statut / paiement)
        $base = fn ($from, $to) => $this->baseQuery($scope['store_ids'], $filters['search'] ?? null, $this->scope->sellerId($request->user()))
            ->whereBetween('sales.created_at', [$from, $to]);

        $current = $this->figures($base($start, $end));
        $previous = $this->figures($base($previousStart, $previousEnd));

        $list = $this->applyStatusFilters($base($start, $end), $filters)
            ->with(['store:id,name', 'seller:id,first_name,last_name', 'customer:id,name,phone', 'invoice'])
            ->when(($filters['sort'] ?? 'recent') === 'amount',
                fn ($q) => $q->orderByDesc('total_amount'),
                fn ($q) => $q->orderByDesc('created_at'))
            ->orderByDesc('id')
            ->paginate($filters['perPage'] ?? 15);

        return response()->json([
            'data' => collect($list->items())->map(fn (Sale $sale) => $this->summary($sale)),
            'meta' => [
                'current_page' => $list->currentPage(),
                'per_page' => $list->perPage(),
                'total' => $list->total(),
                'last_page' => $list->lastPage(),
            ],
            'summary' => [
                'revenue' => ['value' => $current['revenue'], 'previous' => $previous['revenue']],
                'sales_count' => ['value' => $current['count'], 'previous' => $previous['count']],
                'average_basket' => ['value' => $current['average'], 'previous' => $previous['average']],
                'collected' => $current['collected'],
                'outstanding' => $current['outstanding'],
                'cancelled_count' => $current['cancelled_count'],
                'cancelled_amount' => $current['cancelled_amount'],
                'pending_count' => $current['pending_count'],
            ],
            'series' => $this->series($base($start, $end), $start, $end, $granularity),
            'by_seller' => $this->bySeller($base($start, $end)),
            'by_payment_type' => $this->byPaymentType($base($start, $end)),
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'days' => $days,
                'granularity' => $granularity,
                'previous_start' => $previousStart->toDateString(),
                'previous_end' => $previousEnd->toDateString(),
            ],
            'currency' => config('subscriptions.default_currency', 'XOF'),
            'excluded_stores' => $scope['excluded'],
        ]);
    }

    /**
     * Export CSV (séparateur « ; », BOM UTF-8 pour Excel) des ventes filtrées.
     */
    public function export(Request $request)
    {
        $filters = $this->validateFilters($request);
        $scope = $this->scope->resolve($request);

        $query = $this->applyStatusFilters(
            $this->baseQuery($scope['store_ids'], $filters['search'] ?? null, $this->scope->sellerId($request->user()))->whereBetween('sales.created_at', [
                CarbonImmutable::parse($filters['start'])->startOfDay(),
                CarbonImmutable::parse($filters['end'])->endOfDay(),
            ]),
            $filters
        )->with(['store:id,name', 'seller:id,first_name,last_name', 'customer:id,name', 'invoice'])
            ->orderBy('sales.created_at');

        $statusLabels = ['pending' => 'En attente', 'confirmed' => 'Validée', 'cancelled' => 'Annulée'];
        $paymentLabels = ['no_paid' => 'Non payée', 'partial' => 'Partielle', 'paid' => 'Payée', 'cancelled' => 'Annulée'];
        $filename = sprintf('ventes_%s_%s.csv', $filters['start'], $filters['end']);

        return response()->streamDownload(function () use ($query, $statusLabels, $paymentLabels) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['N° vente', 'Date', 'Boutique', 'Client', 'Vendeur', 'Statut', 'Paiement', 'Montant', 'Payé', 'Reste'], ';');

            $query->chunk(500, function ($sales) use ($out, $statusLabels, $paymentLabels) {
                foreach ($sales as $sale) {
                    $row = $this->summary($sale);
                    $cancelled = $sale->status === 'cancelled';
                    fputcsv($out, [
                        $row['sale_number'],
                        $sale->created_at?->format('d/m/Y H:i'),
                        $row['store']['name'] ?? '',
                        $row['customer'],
                        $row['seller'] ?? '',
                        $statusLabels[$sale->status] ?? $sale->status,
                        $paymentLabels[$sale->status_payment] ?? $sale->status_payment,
                        (int) $sale->total_amount,
                        $cancelled ? 0 : (int) ($row['invoice']['amount_paid'] ?? 0),
                        $cancelled ? 0 : (int) ($row['invoice']['balance'] ?? 0),
                    ], ';');
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Rapport PDF des ventes filtrées : synthèse de la période, détail et paiements par moyen. */
    public function exportPdf(Request $request, PdfReportService $reports)
    {
        $filters = $this->validateFilters($request);
        $scope = $this->scope->resolve($request);
        $stores = $this->scope->stores($request->user())->whereIn('id', $scope['store_ids'])->values();

        $range = [
            CarbonImmutable::parse($filters['start'])->startOfDay(),
            CarbonImmutable::parse($filters['end'])->endOfDay(),
        ];
        $base = fn () => $this->baseQuery($scope['store_ids'], $filters['search'] ?? null, $this->scope->sellerId($request->user()))->whereBetween('sales.created_at', $range);
        $filtered = fn () => $this->applyStatusFilters($base(), $filters);

        $figures = $this->figures($base());
        $totals = $filtered()
            ->leftJoin('invoices', fn ($j) => $j->on('invoices.sale_id', '=', 'sales.id')->whereNull('invoices.deleted_at'))
            ->where('sales.status', 'confirmed')
            ->selectRaw('COALESCE(SUM(sales.total_amount), 0) AS amount, COALESCE(SUM(invoices.amount_paid), 0) AS paid, COALESCE(SUM(invoices.balance), 0) AS balance')
            ->first();

        $rows = $filtered()
            ->with(['store:id,name', 'seller:id,first_name,last_name', 'customer:id,name', 'invoice'])
            ->when(($filters['sort'] ?? 'recent') === 'amount',
                fn ($q) => $q->orderByDesc('total_amount'),
                fn ($q) => $q->orderByDesc('created_at'))
            ->orderByDesc('id')
            ->limit(PdfReportService::MAX_ROWS)
            ->get()
            ->map(fn (Sale $sale) => $this->summary($sale))
            ->all();

        $statusLabels = ['pending' => 'En attente', 'confirmed' => 'Validée', 'cancelled' => 'Annulée'];
        $paymentLabels = ['no_paid' => 'Non payée', 'partial' => 'Partielle', 'paid' => 'Payée', 'cancelled' => 'Annulée', 'due' => 'À encaisser'];
        $filtersLabel = collect([
            ($filters['status'] ?? null) ? 'statut « ' . $statusLabels[$filters['status']] . ' »' : null,
            ($filters['payment_status'] ?? null) ? 'paiement « ' . $paymentLabels[$filters['payment_status']] . ' »' : null,
            ($filters['search'] ?? null) ? 'recherche « ' . $filters['search'] . ' »' : null,
        ])->filter()->implode(', ');

        return $reports->download('pdf.sales-report', [
            'title' => 'Rapport des ventes',
            'issuer' => $reports->issuer($stores, $scope['selected']),
            'periodLabel' => $reports->periodLabel($filters['start'], $filters['end']),
            'filtersLabel' => $filtersLabel,
            'summary' => $figures,
            'totals' => ['amount' => (float) $totals->amount, 'paid' => (float) $totals->paid, 'balance' => (float) $totals->balance],
            'rows' => $rows,
            'total' => $filtered()->count(),
            'showStore' => !$scope['selected'] && $stores->count() > 1,
            'byPaymentType' => $this->byPaymentType($base()),
            'statusLabels' => $statusLabels,
            'paymentLabels' => $paymentLabels,
            'typeLabels' => ['cash' => 'Espèces', 'wave' => 'Wave', 'OM' => 'Orange Money', 'other' => 'Autre'],
            'currency' => config('subscriptions.default_currency', 'XOF'),
        ], sprintf('ventes_%s_%s.pdf', $filters['start'], $filters['end']), $request->user(), 'landscape');
    }

    public function show(Request $request, $id)
    {
        $scope = $this->scope->resolve($request->merge(['store_id' => null]));
        $companyStoreIds = $this->scope->stores($request->user())->pluck('id');

        $sale = Sale::with([
            'store:id,name',
            'seller:id,first_name,last_name',
            'customer',
            'saleLineItems.product:id,name,base_unit',
            'saleLineItems.serialNumbers',
            'invoice.paymentReceipts.user:id,first_name,last_name',
        ])->whereIn('store_id', $companyStoreIds)
            ->when($this->scope->sellerId($request->user()), fn ($q, $sellerId) => $q->where('seller_id', $sellerId))
            ->find($id);

        if (!$sale) {
            return response()->json(['status' => false, 'message' => 'Vente introuvable.'], 404);
        }

        // Une boutique expirée reste inaccessible, même via un lien direct
        if (!in_array($sale->store_id, $scope['store_ids'], true)) {
            return response()->json([
                'status' => false,
                'code' => 'STORE_SUBSCRIPTION_EXPIRED',
                'message' => 'L\'abonnement de cette boutique a expiré.',
            ], 403);
        }

        return response()->json([
            'data' => $this->summary($sale) + [
                'gross_amount' => (float) $sale->gross_amount,
                'discount' => (float) $sale->discount,
                'customer_phone' => $sale->customer?->phone,
                'items' => $sale->saleLineItems->map(fn ($item) => [
                    'id' => $item->id,
                    'product' => $item->product?->name ?? 'Produit supprimé',
                    'unit' => $item->unit_name ?? $item->product?->base_unit,
                    'quantity' => (float) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'subtotal' => (float) $item->subtotal,
                    'serial_numbers' => $item->serialNumbers->pluck('serial_number')->filter()->values(),
                ])->values(),
                'payments' => $sale->invoice
                    ? $sale->invoice->paymentReceipts->sortBy('date')->map(fn ($p) => [
                        'id' => $p->id,
                        'date' => (string) $p->date,
                        'amount' => (float) $p->amount,
                        'type' => $p->payment_type,
                        'user' => $p->user ? trim($p->user->first_name . ' ' . $p->user->last_name) : null,
                    ])->values()
                    : [],
            ],
            'currency' => config('subscriptions.default_currency', 'XOF'),
        ]);
    }

    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'start' => 'required|date',
            'end' => 'required|date|after_or_equal:start',
            'status' => 'nullable|in:pending,confirmed,cancelled',
            'payment_status' => 'nullable|in:no_paid,partial,paid,cancelled,due',
            'search' => 'nullable|string|max:100',
            'sort' => 'nullable|in:recent,amount',
            'perPage' => 'nullable|integer|min:1|max:100',
        ]);
    }

    /** Ventes des boutiques du périmètre (d'un seul vendeur si précisé), avec la recherche libre. */
    private function baseQuery(array $storeIds, ?string $search, ?int $sellerId = null): Builder
    {
        return Sale::query()
            ->whereIn('sales.store_id', $storeIds)
            ->when($sellerId, fn ($q, $id) => $q->where('sales.seller_id', $id))
            ->when($search, function ($q, $search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('sale_number', 'ILIKE', "%{$search}%")
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'ILIKE', "%{$search}%")
                            ->orWhere('phone', 'ILIKE', "%{$search}%"))
                        ->orWhereHas('seller', fn ($u) => $u->where('first_name', 'ILIKE', "%{$search}%")
                            ->orWhere('last_name', 'ILIKE', "%{$search}%"))
                        ->orWhereHas('invoice', fn ($i) => $i->where('invoice_number', 'ILIKE', "%{$search}%")
                            ->orWhere('customer_name', 'ILIKE', "%{$search}%"));
                });
            });
    }

    private function applyStatusFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('sales.status', $s))
            ->when($filters['payment_status'] ?? null, fn ($q, $s) => $s === 'due'
                // « À encaisser » : partiellement payées ou non payées
                ? $q->whereIn('sales.status_payment', ['partial', 'no_paid'])
                : $q->where('sales.status_payment', $s));
    }

    /** Chiffres d'une période : ventes validées pour le CA, encaissé et reste dû sur ces mêmes ventes. */
    private function figures(Builder $query): array
    {
        $row = (clone $query)
            ->leftJoin('invoices', fn ($j) => $j->on('invoices.sale_id', '=', 'sales.id')->whereNull('invoices.deleted_at'))
            ->selectRaw("
                COUNT(*) FILTER (WHERE sales.status = 'confirmed') AS n,
                COALESCE(SUM(sales.total_amount) FILTER (WHERE sales.status = 'confirmed'), 0) AS revenue,
                COALESCE(SUM(invoices.amount_paid) FILTER (WHERE sales.status = 'confirmed'), 0) AS collected,
                COALESCE(SUM(invoices.balance) FILTER (WHERE sales.status = 'confirmed'), 0) AS outstanding,
                COUNT(*) FILTER (WHERE sales.status = 'cancelled') AS cancelled_n,
                COALESCE(SUM(sales.total_amount) FILTER (WHERE sales.status = 'cancelled'), 0) AS cancelled_amount,
                COUNT(*) FILTER (WHERE sales.status = 'pending') AS pending_n
            ")
            ->first();

        $count = (int) $row->n;
        $revenue = (float) $row->revenue;

        return [
            'count' => $count,
            'revenue' => $revenue,
            'average' => $count > 0 ? round($revenue / $count) : 0,
            'collected' => (float) $row->collected,
            'outstanding' => (float) $row->outstanding,
            'cancelled_count' => (int) $row->cancelled_n,
            'cancelled_amount' => (float) $row->cancelled_amount,
            'pending_count' => (int) $row->pending_n,
        ];
    }

    private function series(Builder $query, CarbonImmutable $start, CarbonImmutable $end, string $granularity): array
    {
        $rows = $query->where('sales.status', 'confirmed')
            ->selectRaw("DATE(DATE_TRUNC('{$granularity}', sales.created_at)) AS bucket, SUM(sales.total_amount) AS value")
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

    private function bySeller(Builder $query): array
    {
        return $query->where('sales.status', 'confirmed')
            ->join('users', 'users.id', '=', 'sales.seller_id')
            ->groupBy('users.id', 'users.first_name', 'users.last_name')
            ->selectRaw('users.id, users.first_name, users.last_name, COUNT(*) AS n, SUM(sales.total_amount) AS total')
            ->orderByDesc('total')
            ->limit(6)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => trim($r->first_name . ' ' . $r->last_name),
                'sales_count' => (int) $r->n,
                'revenue' => (float) $r->total,
            ])
            ->all();
    }

    /** Paiements reçus sur les ventes validées de la période, par moyen de paiement. */
    private function byPaymentType(Builder $query): array
    {
        $saleIds = (clone $query)->where('sales.status', 'confirmed')->select('sales.id');

        return DB::table('payment_receipts')
            ->join('invoices', 'invoices.id', '=', 'payment_receipts.invoice_id')
            ->whereIn('invoices.sale_id', $saleIds)
            ->whereNull('payment_receipts.deleted_at')
            ->groupBy('payment_receipts.payment_type')
            ->selectRaw('payment_receipts.payment_type AS type, COUNT(*) AS n, SUM(payment_receipts.amount) AS total')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['type' => $r->type, 'count' => (int) $r->n, 'total' => (float) $r->total])
            ->all();
    }

    private function summary(Sale $sale): array
    {
        $invoice = $sale->invoice;

        return [
            'id' => $sale->id,
            'sale_number' => $sale->sale_number,
            'date' => $sale->created_at?->toIso8601String(),
            'store' => $sale->store ? ['id' => $sale->store->id, 'name' => $sale->store->name] : null,
            'seller' => $sale->seller ? trim($sale->seller->first_name . ' ' . $sale->seller->last_name) : null,
            'customer' => $sale->customer?->name ?? $invoice?->customer_name ?? 'Client anonyme',
            'total_amount' => (float) $sale->total_amount,
            'status' => $sale->status,
            'payment_status' => $sale->status_payment,
            'invoice' => $invoice ? [
                'number' => $invoice->invoice_number,
                'amount_paid' => (float) $invoice->amount_paid,
                'balance' => (float) $invoice->balance,
            ] : null,
        ];
    }
}
