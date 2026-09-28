<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PaymentReceipt;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleLineItem;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Indicateurs d'un propriétaire sur un ensemble de boutiques de son entreprise,
 * pour une période (bornes incluses) comparée à la période précédente de même durée.
 */
class OwnerStatsService
{
    /** Vendeur dont on limite les chiffres (tableau de bord d'un vendeur), sinon toute la boutique. */
    private ?int $sellerId = null;

    public function __construct(protected SubscriptionService $subscriptions, protected StorefrontService $storefront)
    {
    }

    /**
     * @param list<int> $storeIds
     */
    /**
     * @param ?int $sellerId vendeur connecté : ses ventes, ses encaissements et ce que ses clients doivent,
     *                       sans les dépenses ni la répartition par boutique
     */
    public function dashboard(array $storeIds, CarbonImmutable $start, CarbonImmutable $end, Collection $storesById, ?int $sellerId = null): array
    {
        $this->sellerId = $sellerId;

        [$start, $end, $previousStart, $previousEnd, $days] = $this->periods($start, $end);
        $granularity = $days <= 45 ? 'day' : ($days <= 190 ? 'week' : 'month');

        $current = $this->kpis($storeIds, $start, $end);
        $previous = $this->kpis($storeIds, $previousStart, $previousEnd);

        $kpis = [];
        foreach ($current as $key => $value) {
            $kpis[$key] = ['value' => $value, 'previous' => $previous[$key]];
        }

        return [
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'days' => $days,
                'granularity' => $granularity,
                'previous_start' => $previousStart->toDateString(),
                'previous_end' => $previousEnd->toDateString(),
            ],
            'kpis' => $kpis,
            // Indépendant de la période : ce qui reste dû et le stock à surveiller aujourd'hui
            'receivables' => $this->receivables($storeIds),
            'series' => [
                'sales_revenue' => $this->revenueSeries($storeIds, $start, $end, $granularity),
            ],
            'top_products' => $this->topProducts($storeIds, $start, $end),
            'by_store' => count($storeIds) > 1 && !$sellerId ? $this->byStore($storeIds, $start, $end, $storesById) : [],
            'low_stock' => $this->lowStock($storeIds),
        ];
    }

    /**
     * Vue « Mes boutiques » : chaque boutique de l'entreprise avec ses indicateurs de la période.
     */
    public function storesOverview(Collection $stores, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $this->sellerId = null;
        [$start, $end] = $this->periods($start, $end);
        $ids = $stores->pluck('id')->all();

        $sales = $this->confirmedSales($ids)
            ->whereBetween('sales.created_at', [$start, $end])
            ->groupBy('sales.store_id')
            ->selectRaw('sales.store_id, COUNT(*) AS n, COALESCE(SUM(sales.total_amount), 0) AS total')
            ->get()->keyBy('store_id');

        $products = Product::whereIn('store_id', $ids)
            ->groupBy('store_id')->selectRaw('store_id, COUNT(*) AS n')->pluck('n', 'store_id');

        $lowStock = Product::whereIn('store_id', $ids)
            ->whereColumn('base_unit_quantity', '<=', 'alert_threshold')
            ->groupBy('store_id')->selectRaw('store_id, COUNT(*) AS n')->pluck('n', 'store_id');

        $receivables = $this->openInvoices($ids)
            ->groupBy('store_id')->selectRaw('store_id, COALESCE(SUM(balance), 0) AS total')->pluck('total', 'store_id');

        return $stores->map(function (Store $store) use ($sales, $products, $lowStock, $receivables) {
            $store->loadCount(['managers', 'sellers']);

            return [
                'id' => $store->id,
                'name' => $store->name,
                'address' => $store->address,
                'phone_one' => $store->phone_one,
                'active' => (bool) $store->active,
                'logo_url' => $store->logo_url,
                'primary_color' => $store->effective_primary_color,
                'subscription' => $this->subscriptions->statusForStore($store),
                // Lecture seule : seul l'administrateur active la vitrine
                'storefront' => $this->storefront->link($store),
                'sales_revenue' => (float) ($sales[$store->id]->total ?? 0),
                'sales_count' => (int) ($sales[$store->id]->n ?? 0),
                'products_count' => (int) ($products[$store->id] ?? 0),
                'low_stock_count' => (int) ($lowStock[$store->id] ?? 0),
                'receivables' => (float) ($receivables[$store->id] ?? 0),
                'team_count' => $store->managers_count + $store->sellers_count,
            ];
        })->values()->all();
    }

    private function periods(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $start = $start->startOfDay();
        $end = $end->endOfDay();
        $days = (int) $start->diffInDays($end->startOfDay()) + 1;
        $previousEnd = $start->subDay()->endOfDay();
        $previousStart = $previousEnd->subDays($days - 1)->startOfDay();

        return [$start, $end, $previousStart, $previousEnd, $days];
    }

    private function kpis(array $storeIds, $from, $to): array
    {
        $sales = $this->confirmedSales($storeIds)
            ->whereBetween('sales.created_at', [$from, $to])
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(sales.total_amount), 0) AS total')
            ->first();

        $cashIn = PaymentReceipt::query()
            ->join('invoices', 'invoices.id', '=', 'payment_receipts.invoice_id')
            ->whereIn('invoices.store_id', $storeIds)
            ->when($this->sellerId, fn ($q, $id) => $q->where('payment_receipts.user_id', $id))
            ->where('invoices.is_cancelled', false)
            ->whereBetween('payment_receipts.date', [$from->toDateString(), $to->toDateString()])
            ->sum('payment_receipts.amount');

        $expenses = $this->sellerId ? 0 : Expense::whereIn('store_id', $storeIds)
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->sum('amount');

        $count = (int) $sales->n;
        $revenue = (float) $sales->total;

        return [
            'sales_revenue' => $revenue,
            'sales_count' => $count,
            'average_basket' => $count > 0 ? round($revenue / $count) : 0,
            'cash_in' => (float) $cashIn,
            'expenses' => (float) $expenses,
        ];
    }

    private function receivables(array $storeIds): array
    {
        $row = $this->openInvoices($storeIds)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(balance), 0) AS total')
            ->first();

        return ['amount' => (float) $row->total, 'invoices' => (int) $row->n];
    }

    private function revenueSeries(array $storeIds, $start, $end, string $granularity): array
    {
        $rows = $this->confirmedSales($storeIds)
            ->whereBetween('sales.created_at', [$start, $end])
            ->selectRaw("DATE(DATE_TRUNC('{$granularity}', sales.created_at)) AS bucket, COALESCE(SUM(sales.total_amount), 0) AS value")
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

    private function topProducts(array $storeIds, $start, $end): array
    {
        return SaleLineItem::query()
            ->join('sales', 'sales.id', '=', 'sale_line_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_line_items.product_id')
            ->whereIn('sales.store_id', $storeIds)
            ->when($this->sellerId, fn ($q, $id) => $q->where('sales.seller_id', $id))
            ->where('sales.status', 'confirmed')
            ->whereNull('sales.deleted_at')
            ->whereBetween('sales.created_at', [$start, $end])
            ->groupBy('products.id', 'products.name', 'products.base_unit')
            ->selectRaw('products.id, products.name, products.base_unit, SUM(sale_line_items.quantity) AS quantity, SUM(sale_line_items.subtotal) AS revenue')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'unit' => $r->base_unit,
                'quantity' => (float) $r->quantity,
                'revenue' => (float) $r->revenue,
            ])
            ->all();
    }

    private function byStore(array $storeIds, $start, $end, Collection $storesById): array
    {
        $rows = $this->confirmedSales($storeIds)
            ->whereBetween('sales.created_at', [$start, $end])
            ->groupBy('sales.store_id')
            ->selectRaw('sales.store_id, COUNT(*) AS n, COALESCE(SUM(sales.total_amount), 0) AS total')
            ->get()
            ->keyBy('store_id');

        return collect($storeIds)
            ->map(fn ($id) => [
                'store_id' => $id,
                'store_name' => $storesById[$id]->name ?? '—',
                'sales_count' => (int) ($rows[$id]->n ?? 0),
                'revenue' => (float) ($rows[$id]->total ?? 0),
            ])
            ->sortByDesc('revenue')
            ->values()
            ->all();
    }

    private function lowStock(array $storeIds): array
    {
        $query = Product::with('store:id,name')
            ->whereIn('store_id', $storeIds)
            ->whereColumn('base_unit_quantity', '<=', 'alert_threshold');

        return [
            'count' => (clone $query)->count(),
            'items' => $query->orderBy('base_unit_quantity')
                ->limit(5)
                ->get()
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'store_name' => $p->store?->name,
                    'quantity' => (float) $p->base_unit_quantity,
                    'alert_threshold' => (int) $p->alert_threshold,
                    'unit' => $p->base_unit,
                ])
                ->all(),
        ];
    }

    /** Ventes validées des boutiques données. */
    private function confirmedSales(array $storeIds): Builder
    {
        return Sale::query()
            ->whereIn('sales.store_id', $storeIds)
            ->when($this->sellerId, fn ($q, $id) => $q->where('sales.seller_id', $id))
            ->where('sales.status', 'confirmed');
    }

    /** Factures non annulées avec un reste à payer. */
    private function openInvoices(array $storeIds): Builder
    {
        return Invoice::query()
            ->whereIn('store_id', $storeIds)
            ->when($this->sellerId, fn ($q, $id) => $q->whereHas('sale', fn ($s) => $s->where('seller_id', $id)))
            ->where('is_cancelled', false)
            ->where('balance', '>', 0);
    }
}
