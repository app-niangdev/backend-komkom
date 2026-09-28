<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Services\OwnerScopeService;
use App\Services\OwnerStatsService;
use App\Services\StorefrontService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Espace propriétaire : ses boutiques et leurs indicateurs.
 */
class OwnerDashboardController extends Controller
{
    public function __construct(
        protected OwnerScopeService $scope,
        protected OwnerStatsService $stats,
        protected StorefrontService $storefront,
    ) {
    }

    /**
     * Boutiques de l'entreprise (léger) : alimente le sélecteur de boutique.
     */
    public function storeOptions(Request $request)
    {
        $subscriptions = app(\App\Services\SubscriptionService::class);

        return response()->json([
            'data' => $this->scope->stores($request->user())->map(fn ($store) => [
                'id' => $store->id,
                'name' => $store->name,
                'active' => (bool) $store->active,
                'uses_measurements' => (bool) ($store->uses_measurements ?? true),
                'subscription' => $subscriptions->statusForStore($store),
            ])->values(),
        ]);
    }

    /**
     * « Mes boutiques » : chaque boutique avec ses indicateurs sur la période.
     */
    public function stores(Request $request)
    {
        [$start, $end] = $this->period($request);

        return response()->json([
            'data' => $this->stats->storesOverview($this->scope->stores($request->user()), $start, $end),
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
        ]);
    }

    /**
     * Tableau de bord d'une boutique (store_id) ou de toutes les boutiques couvertes.
     */
    public function dashboard(Request $request)
    {
        [$start, $end] = $this->period($request);
        $scope = $this->scope->resolve($request);
        $storesById = $this->scope->stores($request->user())->keyBy('id');

        $sellerId = $this->scope->sellerId($request->user());

        return response()->json($this->stats->dashboard($scope['store_ids'], $start, $end, $storesById, $sellerId) + [
            'personal' => $sellerId !== null,
            'currency' => config('subscriptions.default_currency', 'XOF'),
            'store' => $scope['selected'] ? ['id' => $scope['selected']->id, 'name' => $scope['selected']->name] : null,
            // Lien de la vitrine de la boutique affichée (pas pour un vendeur)
            'storefront' => $scope['selected'] && $sellerId === null ? $this->storefront->link($scope['selected']) : null,
            'excluded_stores' => $scope['excluded'],
        ]);
    }

    /** Période demandée (30 derniers jours par défaut), 3 ans maximum. */
    private function period(Request $request): array
    {
        $validated = $request->validate([
            'start' => 'nullable|date',
            'end' => 'nullable|date|after_or_equal:start',
        ], [
            'end.after_or_equal' => 'La date de fin doit être postérieure ou égale à la date de début.',
        ]);

        $end = isset($validated['end']) ? CarbonImmutable::parse($validated['end']) : CarbonImmutable::today();
        $start = isset($validated['start']) ? CarbonImmutable::parse($validated['start']) : $end->subDays(29);

        abort_if($start->diffInDays($end) > 366 * 3, 422, 'La période ne peut pas dépasser 3 ans.');

        return [$start, $end];
    }
}
