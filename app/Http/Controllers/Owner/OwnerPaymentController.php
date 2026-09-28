<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Services\OwnerScopeService;
use App\Services\PdfReportService;
use App\Services\SaleService;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Encaissements : l'argent reçu sur la période, compté au jour où il est reçu.
 *
 * Un client qui paie une partie le 1er et le reste le 30 : chaque montant est compté à sa date
 * de paiement, quelle que soit la date de la vente. Chaque paiement est classé « à la vente »
 * (reçu le jour de la facture) ou « règlement de dette » (reçu un jour suivant).
 * Les paiements de factures annulées sont exclus.
 */
class OwnerPaymentController extends Controller
{
    private const ORIGINS = ['at_sale', 'debt'];

    public function __construct(protected OwnerScopeService $scope)
    {
    }

    public function index(Request $request)
    {
        $filters = $this->validateFilters($request);
        $scope = $this->scope->resolve($request);

        $start = CarbonImmutable::parse($filters['start'])->startOfDay();
        $end = CarbonImmutable::parse($filters['end'])->startOfDay();
        $days = (int) $start->diffInDays($end) + 1;
        $previousEnd = $start->subDay();
        $previousStart = $previousEnd->subDays($days - 1);
        $granularity = $days <= 62 ? 'day' : ($days <= 190 ? 'week' : 'month');

        // Analyses : période + boutique + recherche (indépendantes des filtres moyen / origine)
        $period = fn ($from, $to) => $this->baseQuery($scope['store_ids'], $filters['search'] ?? null, $this->scope->sellerId($request->user()))
            ->whereBetween('payment_receipts.date', [$from->toDateString(), $to->toDateString()]);

        $totals = (clone $period($start, $end))
            ->selectRaw("
                COUNT(*) AS n,
                COALESCE(SUM(payment_receipts.amount), 0) AS total,
                COALESCE(SUM(payment_receipts.amount) FILTER (WHERE {$this->debtCondition()}), 0) AS debt,
                COUNT(*) FILTER (WHERE {$this->debtCondition()}) AS debt_n,
                COUNT(DISTINCT invoices.customer_id) FILTER (WHERE {$this->debtCondition()}) AS debt_customers
            ")
            ->first();
        $previous = (float) (clone $period($previousStart, $previousEnd))->sum('payment_receipts.amount');

        $list = $this->applyListFilters($period($start, $end), $filters)
            ->select([
                'payment_receipts.id',
                'payment_receipts.date',
                'payment_receipts.amount',
                'payment_receipts.payment_type',
                'payment_receipts.created_at AS recorded_at',
                'invoices.id AS invoice_id',
                'invoices.invoice_number',
                'invoices.created_at AS invoice_date',
                'invoices.amount_total',
                'invoices.balance',
                'invoices.customer_name',
                'customers.name AS customer',
                'customers.phone AS customer_phone',
                'sales.sale_number',
                'stores.id AS store_id',
                'stores.name AS store_name',
                DB::raw("TRIM(CONCAT(users.first_name, ' ', users.last_name)) AS cashier"),
            ])
            ->orderByDesc('payment_receipts.date')
            ->orderByDesc('payment_receipts.created_at')
            ->orderByDesc('payment_receipts.id')
            ->paginate($filters['perPage'] ?? 20);

        return response()->json([
            'data' => collect($list->items())->map(fn ($row) => $this->row($row)),
            'meta' => [
                'current_page' => $list->currentPage(),
                'per_page' => $list->perPage(),
                'total' => $list->total(),
                'last_page' => $list->lastPage(),
            ],
            'summary' => [
                'total' => ['value' => (float) $totals->total, 'previous' => $previous],
                'count' => (int) $totals->n,
                'at_sale' => (float) $totals->total - (float) $totals->debt,
                'debt' => (float) $totals->debt,
                'debt_count' => (int) $totals->debt_n,
                'debt_customers' => (int) $totals->debt_customers,
            ],
            'by_type' => (clone $period($start, $end))
                ->groupBy('payment_receipts.payment_type')
                ->selectRaw('payment_receipts.payment_type AS type, COUNT(*) AS n, SUM(payment_receipts.amount) AS total')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($r) => ['type' => $r->type, 'count' => (int) $r->n, 'total' => (float) $r->total]),
            'by_cashier' => (clone $period($start, $end))
                ->groupBy('users.id', 'users.first_name', 'users.last_name')
                ->selectRaw("users.id, TRIM(CONCAT(users.first_name, ' ', users.last_name)) AS name, COUNT(*) AS n, SUM(payment_receipts.amount) AS total")
                ->orderByDesc('total')
                ->limit(6)
                ->get()
                ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'count' => (int) $r->n, 'total' => (float) $r->total]),
            'series' => $this->series($period($start, $end), $start, $end, $granularity),
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

    /** Export CSV (« ; », BOM UTF-8 pour Excel) des paiements filtrés. */
    public function export(Request $request)
    {
        $filters = $this->validateFilters($request);
        $scope = $this->scope->resolve($request);

        $query = $this->applyListFilters(
            $this->baseQuery($scope['store_ids'], $filters['search'] ?? null, $this->scope->sellerId($request->user()))
                ->whereBetween('payment_receipts.date', [$filters['start'], $filters['end']]),
            $filters
        )->select([
            'payment_receipts.id', 'payment_receipts.date', 'payment_receipts.amount', 'payment_receipts.payment_type',
            'payment_receipts.created_at AS recorded_at', 'invoices.invoice_number', 'invoices.created_at AS invoice_date',
            'invoices.balance', 'invoices.customer_name', 'customers.name AS customer', 'sales.sale_number', 'stores.name AS store_name',
            DB::raw("TRIM(CONCAT(users.first_name, ' ', users.last_name)) AS cashier"),
        ])->orderBy('payment_receipts.date')->orderBy('payment_receipts.id');

        $types = ['cash' => 'Espèces', 'wave' => 'Wave', 'OM' => 'Orange Money', 'other' => 'Autre'];
        $filename = sprintf('encaissements_%s_%s.csv', $filters['start'], $filters['end']);

        return response()->streamDownload(function () use ($query, $types) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'Heure', 'Boutique', 'Client', 'Facture', 'Vente', 'Moyen', 'Origine', 'Montant', 'Reste dû sur la facture', 'Encaissé par'], ';');
            $query->chunk(500, function ($rows) use ($out, $types) {
                foreach ($rows as $r) {
                    $row = $this->row($r);
                    fputcsv($out, [
                        CarbonImmutable::parse($r->date)->format('d/m/Y'),
                        $r->recorded_at ? CarbonImmutable::parse($r->recorded_at)->format('H:i') : '',
                        $r->store_name,
                        $row['customer'],
                        $r->invoice_number,
                        $r->sale_number,
                        $types[$r->payment_type] ?? $r->payment_type,
                        $row['origin'] === 'debt' ? 'Règlement (vente du ' . CarbonImmutable::parse($r->invoice_date)->format('d/m/Y') . ')' : 'À la vente',
                        (int) $r->amount,
                        (int) $r->balance,
                        $r->cashier,
                    ], ';');
                }
            }, 'payment_receipts.id');
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Rapport PDF des encaissements (clôture de caisse pour une journée). */
    public function exportPdf(Request $request, PdfReportService $reports)
    {
        $filters = $this->validateFilters($request);
        $scope = $this->scope->resolve($request);
        $stores = $this->scope->stores($request->user())->whereIn('id', $scope['store_ids'])->values();

        $start = CarbonImmutable::parse($filters['start'])->startOfDay();
        $end = CarbonImmutable::parse($filters['end'])->startOfDay();
        $days = (int) $start->diffInDays($end) + 1;
        $previousEnd = $start->subDay();
        $previousStart = $previousEnd->subDays($days - 1);

        // Les chiffres du rapport suivent les filtres de la liste (moyen, origine, recherche)
        $filtered = fn ($from, $to) => $this->applyListFilters(
            $this->baseQuery($scope['store_ids'], $filters['search'] ?? null, $this->scope->sellerId($request->user()))
                ->whereBetween('payment_receipts.date', [$from->toDateString(), $to->toDateString()]),
            $filters
        );

        $totals = $filtered($start, $end)
            ->selectRaw("
                COUNT(*) AS n,
                COALESCE(SUM(payment_receipts.amount), 0) AS total,
                COALESCE(SUM(payment_receipts.amount) FILTER (WHERE {$this->debtCondition()}), 0) AS debt,
                COUNT(*) FILTER (WHERE {$this->debtCondition()}) AS debt_n,
                COUNT(DISTINCT invoices.customer_id) FILTER (WHERE {$this->debtCondition()}) AS debt_customers
            ")
            ->first();

        $rows = $filtered($start, $end)
            ->select([
                'payment_receipts.id', 'payment_receipts.date', 'payment_receipts.amount', 'payment_receipts.payment_type',
                'payment_receipts.created_at AS recorded_at', 'invoices.id AS invoice_id', 'invoices.invoice_number',
                'invoices.created_at AS invoice_date', 'invoices.amount_total', 'invoices.balance', 'invoices.customer_name',
                'customers.name AS customer', 'customers.phone AS customer_phone', 'sales.sale_number',
                'stores.id AS store_id', 'stores.name AS store_name',
                DB::raw("TRIM(CONCAT(users.first_name, ' ', users.last_name)) AS cashier"),
            ])
            ->orderByDesc('payment_receipts.date')
            ->orderByDesc('payment_receipts.created_at')
            ->orderByDesc('payment_receipts.id')
            ->limit(PdfReportService::MAX_ROWS)
            ->get()
            ->map(fn ($r) => $this->row($r))
            ->all();

        $byType = $filtered($start, $end)
            ->groupBy('payment_receipts.payment_type')
            ->selectRaw('payment_receipts.payment_type AS type, COUNT(*) AS n, SUM(payment_receipts.amount) AS total')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['type' => $r->type, 'count' => (int) $r->n, 'total' => (float) $r->total])
            ->all();

        $byDay = $filtered($start, $end)
            ->groupBy('payment_receipts.date')
            ->selectRaw("payment_receipts.date AS day, COUNT(*) AS n, SUM(payment_receipts.amount) AS total,
                COALESCE(SUM(payment_receipts.amount) FILTER (WHERE {$this->debtCondition()}), 0) AS debt")
            ->orderBy('payment_receipts.date')
            ->get()
            ->map(fn ($r) => ['day' => (string) $r->day, 'count' => (int) $r->n, 'total' => (float) $r->total, 'debt' => (float) $r->debt])
            ->all();

        $typeLabels = ['cash' => 'Espèces', 'wave' => 'Wave', 'OM' => 'Orange Money', 'other' => 'Autre'];
        $filtersLabel = collect([
            ($filters['type'] ?? null) ? 'moyen « ' . $typeLabels[$filters['type']] . ' »' : null,
            ($filters['origin'] ?? null) ? ($filters['origin'] === 'debt' ? 'règlements de dettes uniquement' : 'paiements à la vente uniquement') : null,
            ($filters['search'] ?? null) ? 'recherche « ' . $filters['search'] . ' »' : null,
        ])->filter()->implode(', ');

        return $reports->download('pdf.payments-report', [
            'title' => $days === 1 ? 'Encaissements de la journée' : 'Rapport des encaissements',
            'issuer' => $reports->issuer($stores, $scope['selected']),
            'periodLabel' => $reports->periodLabel($filters['start'], $filters['end']),
            'filtersLabel' => $filtersLabel,
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'summary' => [
                'total' => (float) $totals->total,
                'count' => (int) $totals->n,
                'at_sale' => (float) $totals->total - (float) $totals->debt,
                'debt' => (float) $totals->debt,
                'debt_count' => (int) $totals->debt_n,
                'debt_customers' => (int) $totals->debt_customers,
                'previous' => (float) $filtered($previousStart, $previousEnd)->sum('payment_receipts.amount'),
            ],
            'byType' => $byType,
            'byDay' => $byDay,
            'rows' => $rows,
            'total' => (int) $totals->n,
            'showStore' => !$scope['selected'] && $stores->count() > 1,
            'typeLabels' => $typeLabels,
            'currency' => config('subscriptions.default_currency', 'XOF'),
        ], sprintf('encaissements_%s_%s.pdf', $filters['start'], $filters['end']), $request->user());
    }

    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'start' => 'required|date',
            'end' => 'required|date|after_or_equal:start',
            'type' => ['nullable', Rule::in(SaleService::PAYMENT_TYPES)],
            'origin' => ['nullable', Rule::in(self::ORIGINS)],
            'search' => 'nullable|string|max:100',
            'perPage' => 'nullable|integer|min:1|max:100',
        ]);
    }

    /** Paiements des boutiques du périmètre, hors factures annulées, avec la recherche libre. */
    /** Paiements du périmètre ; pour un vendeur, ceux qu'il a lui-même encaissés. */
    private function baseQuery(array $storeIds, ?string $search, ?int $cashierId = null): Builder
    {
        return DB::table('payment_receipts')
            ->join('invoices', 'invoices.id', '=', 'payment_receipts.invoice_id')
            ->join('stores', 'stores.id', '=', 'invoices.store_id')
            ->leftJoin('sales', 'sales.id', '=', 'invoices.sale_id')
            ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
            ->leftJoin('users', 'users.id', '=', 'payment_receipts.user_id')
            ->whereIn('invoices.store_id', $storeIds)
            ->when($cashierId, fn ($q, $id) => $q->where('payment_receipts.user_id', $id))
            ->whereNull('payment_receipts.deleted_at')
            ->whereNull('invoices.deleted_at')
            ->where('invoices.is_cancelled', false)
            ->where('invoices.invoice_status', '!=', 'cancelled')
            ->when($search, fn ($q, $s) => $q->where(fn ($sub) => $sub
                ->where('invoices.invoice_number', 'ILIKE', "%{$s}%")
                ->orWhere('sales.sale_number', 'ILIKE', "%{$s}%")
                ->orWhere('invoices.customer_name', 'ILIKE', "%{$s}%")
                ->orWhere('customers.name', 'ILIKE', "%{$s}%")
                ->orWhere('customers.phone', 'ILIKE', "%{$s}%")));
    }

    private function applyListFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('payment_receipts.payment_type', $t))
            ->when($filters['origin'] ?? null, fn ($q, $o) => $o === 'debt'
                ? $q->whereRaw($this->debtCondition())
                : $q->whereRaw("NOT ({$this->debtCondition()})"));
    }

    /** Paiement reçu un jour postérieur à la facture : règlement d'une dette. */
    private function debtCondition(): string
    {
        return 'payment_receipts.date > DATE(invoices.created_at)';
    }

    private function series(Builder $query, CarbonImmutable $start, CarbonImmutable $end, string $granularity): array
    {
        $rows = $query
            ->selectRaw("DATE(DATE_TRUNC('{$granularity}', payment_receipts.date)) AS bucket, SUM(payment_receipts.amount) AS value")
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

    private function row(object $r): array
    {
        $paidOn = CarbonImmutable::parse($r->date)->toDateString();
        $invoiceDay = $r->invoice_date ? CarbonImmutable::parse($r->invoice_date)->toDateString() : $paidOn;
        $customer = $r->customer ?? ($r->customer_name && $r->customer_name !== 'Anonyme' ? $r->customer_name : null);

        return [
            'id' => $r->id,
            'date' => $paidOn,
            'recorded_at' => $r->recorded_at ? CarbonImmutable::parse($r->recorded_at)->toIso8601String() : null,
            'amount' => (float) $r->amount,
            'type' => $r->payment_type,
            'origin' => $paidOn > $invoiceDay ? 'debt' : 'at_sale',
            'invoice' => [
                'id' => $r->invoice_id ?? null,
                'number' => $r->invoice_number,
                'date' => $invoiceDay,
                'amount_total' => isset($r->amount_total) ? (float) $r->amount_total : null,
                'balance' => (float) $r->balance,
            ],
            'sale_number' => $r->sale_number,
            'customer' => $customer ?? 'Client anonyme',
            'customer_phone' => $r->customer_phone ?? null,
            'store' => isset($r->store_id) ? ['id' => $r->store_id, 'name' => $r->store_name] : null,
            'cashier' => $r->cashier ?: null,
        ];
    }
}
