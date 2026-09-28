<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Sale;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;

/**
 * Statistiques de la plateforme pour l'administrateur, sur une période donnée
 * (bornes incluses), comparées à la période précédente de même durée.
 */
class AdminStatsService
{
    public function __construct(protected SubscriptionService $subscriptions)
    {
    }

    public function build(CarbonImmutable $start, CarbonImmutable $end, ?int $companyId): array
    {
        $start = $start->startOfDay();
        $end = $end->endOfDay();

        $days = (int) $start->diffInDays($end->startOfDay()) + 1;
        $previousEnd = $start->subDay()->endOfDay();
        $previousStart = $previousEnd->subDays($days - 1)->startOfDay();

        $granularity = $this->granularity($days);

        return [
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'days' => $days,
                'granularity' => $granularity,
                'previous_start' => $previousStart->toDateString(),
                'previous_end' => $previousEnd->toDateString(),
            ],
            'currency' => config('subscriptions.default_currency', 'XOF'),
            'kpis' => $this->kpis($start, $end, $previousStart, $previousEnd, $companyId),
            'series' => [
                'subscription_revenue' => $this->series(
                    $this->subscriptionQuery($companyId), 'subscriptions.created_at', 'SUM(subscriptions.amount)',
                    $start, $end, $granularity
                ),
                'sales_revenue' => $this->series(
                    $this->salesQuery($companyId), 'sales.created_at', 'SUM(sales.total_amount)',
                    $start, $end, $granularity
                ),
            ],
            'top_companies' => $this->topCompanies($start, $end, $companyId),
            'subscription_states' => $this->subscriptionSnapshot($companyId),
        ];
    }

    /** Jour jusqu'à ~6 semaines, semaine jusqu'à ~6 mois, mois au-delà. */
    private function granularity(int $days): string
    {
        return match (true) {
            $days <= 45 => 'day',
            $days <= 190 => 'week',
            default => 'month',
        };
    }

    private function kpis($start, $end, $previousStart, $previousEnd, ?int $companyId): array
    {
        $compute = function ($from, $to) use ($companyId) {
            $sales = $this->salesQuery($companyId)
                ->whereBetween('sales.created_at', [$from, $to])
                ->selectRaw('COUNT(*) AS n, COALESCE(SUM(sales.total_amount), 0) AS total')
                ->first();

            $paidSubscriptions = $this->subscriptionQuery($companyId)
                ->whereBetween('subscriptions.created_at', [$from, $to])
                ->selectRaw('COUNT(*) FILTER (WHERE subscriptions.amount > 0) AS n, COALESCE(SUM(subscriptions.amount), 0) AS total')
                ->first();

            return [
                'subscription_revenue' => (float) $paidSubscriptions->total,
                'paid_subscriptions' => (int) $paidSubscriptions->n,
                'sales_revenue' => (float) $sales->total,
                'sales_count' => (int) $sales->n,
                'new_companies' => Company::query()
                    ->when($companyId, fn ($q) => $q->where('id', $companyId))
                    ->whereBetween('created_at', [$from, $to])->count(),
                'new_stores' => Store::query()
                    ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                    ->whereBetween('created_at', [$from, $to])->count(),
                'new_users' => User::query()
                    ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                    ->whereBetween('created_at', [$from, $to])->count(),
            ];
        };

        $current = $compute($start, $end);
        $previous = $compute($previousStart, $previousEnd);

        $kpis = [];
        foreach ($current as $key => $value) {
            $kpis[$key] = ['value' => $value, 'previous' => $previous[$key]];
        }

        return $kpis;
    }

    /**
     * Série temporelle complète (les intervalles sans donnée valent 0).
     *
     * @return list<array{bucket:string, value:float}>
     */
    private function series(Builder $query, string $column, string $aggregate, $start, $end, string $granularity): array
    {
        $rows = $query
            ->whereBetween($column, [$start, $end])
            ->selectRaw("DATE(DATE_TRUNC('{$granularity}', {$column})) AS bucket, COALESCE({$aggregate}, 0) AS value")
            ->groupBy('bucket')
            ->pluck('value', 'bucket');

        $cursor = match ($granularity) {
            'week' => $start->startOfWeek(),
            'month' => $start->startOfMonth(),
            default => $start,
        };
        $step = match ($granularity) {
            'week' => '1 week',
            'month' => '1 month',
            default => '1 day',
        };

        $points = [];
        foreach (CarbonPeriod::create($cursor, $step, $end) as $bucket) {
            $key = $bucket->toDateString();
            $points[] = ['bucket' => $key, 'value' => (float) ($rows[$key] ?? 0)];
        }

        return $points;
    }

    private function topCompanies($start, $end, ?int $companyId): array
    {
        return $this->salesQuery($companyId)
            ->join('companies', 'companies.id', '=', 'stores.company_id')
            ->whereBetween('sales.created_at', [$start, $end])
            ->groupBy('companies.id', 'companies.name')
            ->selectRaw('companies.id, companies.name, COUNT(*) AS sales_count, SUM(sales.total_amount) AS revenue')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'sales_count' => (int) $row->sales_count,
                'revenue' => (float) $row->revenue,
            ])
            ->all();
    }

    /** Photographie actuelle des abonnements (indépendante de la période). */
    private function subscriptionSnapshot(?int $companyId): array
    {
        $statuses = Store::with(['company', 'subscriptions'])
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->get()
            ->map(fn (Store $store) => $this->subscriptions->statusForStore($store));

        $counts = ['active' => 0, 'expiring' => 0, 'expired' => 0, 'none' => 0];
        foreach ($statuses as $status) {
            $counts[$status['state']]++;
        }

        return [
            'counts' => $counts,
            // Boutiques à traiter en priorité : bientôt échues puis expirées / sans abonnement
            'attention' => $statuses
                ->filter(fn ($s) => $s['state'] !== SubscriptionService::ACTIVE)
                ->sortBy(fn ($s) => [$s['state'] === SubscriptionService::EXPIRING ? 0 : 1, $s['days_left'] ?? PHP_INT_MIN])
                ->take(5)
                ->values()
                ->all(),
        ];
    }

    /** Ventes validées, restreintes à une entreprise le cas échéant. */
    private function salesQuery(?int $companyId): Builder
    {
        return Sale::query()
            ->join('stores', 'stores.id', '=', 'sales.store_id')
            ->where('sales.status', 'confirmed')
            ->when($companyId, fn ($q) => $q->where('stores.company_id', $companyId));
    }

    /** Abonnements saisis (date d'enregistrement = date d'encaissement), en devise par défaut. */
    private function subscriptionQuery(?int $companyId): Builder
    {
        return Subscription::query()
            ->join('stores', 'stores.id', '=', 'subscriptions.store_id')
            ->where('subscriptions.currency', config('subscriptions.default_currency', 'XOF'))
            ->when($companyId, fn ($q) => $q->where('stores.company_id', $companyId));
    }
}
