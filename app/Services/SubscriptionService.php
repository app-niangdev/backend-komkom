<?php

namespace App\Services;

use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Calcule l'état d'abonnement des boutiques et l'accès qui en découle.
 *
 * États : active | expiring (échéance dans moins de `warning_days` jours) | expired | none.
 * Les abonnements qui se suivent sans interruption (renouvellement anticipé) sont chaînés :
 * l'échéance retenue est la fin de la période couverte en continu à partir d'aujourd'hui.
 */
class SubscriptionService
{
    public const ACTIVE = 'active';
    public const EXPIRING = 'expiring';
    public const EXPIRED = 'expired';
    public const NONE = 'none';

    public function warningDays(): int
    {
        return (int) config('subscriptions.warning_days', 5);
    }

    /**
     * @return array{store_id:int, store_name:string, company_name:?string, state:string, plan:?string,
     *               starts_at:?string, ends_at:?string, days_left:?int}
     */
    public function statusForStore(Store $store): array
    {
        $today = CarbonImmutable::today();
        $subscriptions = $store->relationLoaded('subscriptions')
            ? $store->subscriptions
            : $store->subscriptions()->get();

        $current = $subscriptions->first(
            fn (Subscription $s) => $s->starts_at->lte($today) && $s->ends_at->gte($today)
        );

        $base = [
            'store_id' => $store->id,
            'store_name' => $store->name,
            'company_name' => $store->company?->name,
        ];

        if (!$current) {
            // Dernier abonnement échu, pour afficher les détails de l'expiration
            $last = $subscriptions
                ->filter(fn (Subscription $s) => $s->ends_at->lt($today))
                ->sortByDesc('ends_at')
                ->first();

            return $base + [
                'state' => $last ? self::EXPIRED : self::NONE,
                'plan' => $last?->plan,
                'starts_at' => $last?->starts_at->toDateString(),
                'ends_at' => $last?->ends_at->toDateString(),
                'days_left' => $last ? -(int) abs($last->ends_at->diffInDays($today)) : null,
            ];
        }

        $coverageEnd = $this->coverageEnd($subscriptions, $current);
        $daysLeft = (int) abs($today->diffInDays($coverageEnd));

        return $base + [
            'state' => $daysLeft <= $this->warningDays() ? self::EXPIRING : self::ACTIVE,
            'plan' => $current->plan,
            'starts_at' => $current->starts_at->toDateString(),
            'ends_at' => $coverageEnd->toDateString(),
            'days_left' => $daysLeft,
        ];
    }

    public function isAccessible(Store $store): bool
    {
        return in_array($this->statusForStore($store)['state'], [self::ACTIVE, self::EXPIRING], true);
    }

    /**
     * Boutiques de l'utilisateur concernées par l'abonnement (aucune pour un administrateur).
     *
     * @return Collection<int, Store>
     */
    public function storesForUser(User $user): Collection
    {
        $role = strtolower($user->role?->name ?? '');

        $stores = match ($role) {
            'manager' => collect([$user->manager?->store]),
            'seller' => collect([$user->seller?->store]),
            'owner' => $user->owner?->company?->stores ?? collect(),
            default => collect(),
        };

        $stores = $stores->filter()->values();
        $stores->each(fn (Store $store) => $store->loadMissing(['subscriptions', 'company']));

        return $stores;
    }

    /**
     * Statuts bloquants pour l'utilisateur : vide s'il peut accéder à l'application.
     * Gestionnaire / vendeur : bloqué si sa boutique n'est plus couverte.
     * Propriétaire : bloqué seulement si aucune de ses boutiques n'est couverte.
     */
    public function blockingStatuses(User $user): array
    {
        $stores = $this->storesForUser($user);
        if ($stores->isEmpty()) {
            return [];
        }

        $statuses = $stores->map(fn (Store $store) => $this->statusForStore($store));
        $accessible = $statuses->filter(fn ($s) => in_array($s['state'], [self::ACTIVE, self::EXPIRING], true));

        return $accessible->isEmpty() ? $statuses->values()->all() : [];
    }

    /**
     * Alertes à afficher à la connexion : abonnements proches de l'échéance,
     * et pour un propriétaire, ses boutiques déjà expirées.
     */
    public function alertsForUser(User $user): array
    {
        return $this->storesForUser($user)
            ->map(fn (Store $store) => $this->statusForStore($store))
            ->filter(fn ($s) => $s['state'] === self::EXPIRING
                || in_array($s['state'], [self::EXPIRED, self::NONE], true))
            ->sortBy('days_left')
            ->values()
            ->all();
    }

    /**
     * Réponse 403 standard lorsque l'accès est refusé pour cause d'abonnement.
     * SUBSCRIPTION_EXPIRED : le compte entier est bloqué (déconnexion côté client).
     * STORE_SUBSCRIPTION_EXPIRED : seule la boutique demandée est bloquée (propriétaire).
     */
    public function expiredResponse(array $statuses, string $code = 'SUBSCRIPTION_EXPIRED')
    {
        return response()->json([
            'status' => false,
            'code' => $code,
            'message' => count($statuses) > 1
                ? 'Les abonnements de vos boutiques ont expiré.'
                : 'L\'abonnement de votre boutique a expiré.',
            'subscriptions' => $statuses,
        ], 403);
    }

    /**
     * Crée la période d'essai d'une nouvelle boutique (si configurée).
     */
    public function createTrial(Store $store, ?int $createdBy = null): ?Subscription
    {
        $trialDays = (int) config('subscriptions.trial_days', 30);
        if ($trialDays <= 0) {
            return null;
        }

        $today = CarbonImmutable::today();

        return $store->subscriptions()->create([
            'plan' => 'Essai',
            'amount' => 0,
            'currency' => config('subscriptions.default_currency', 'XOF'),
            'starts_at' => $today,
            'ends_at' => $today->addDays($trialDays - 1),
            'notes' => 'Période d\'essai offerte à la création de la boutique.',
            'created_by' => $createdBy,
        ]);
    }

    private function coverageEnd(Collection $subscriptions, Subscription $current): CarbonImmutable
    {
        $end = CarbonImmutable::parse($current->ends_at);

        foreach ($subscriptions->sortBy('starts_at') as $next) {
            $nextStart = CarbonImmutable::parse($next->starts_at);
            $nextEnd = CarbonImmutable::parse($next->ends_at);

            // Période suivante qui démarre au plus tard le lendemain de la fin courante
            if ($nextStart->lte($end->addDay()) && $nextEnd->gt($end)) {
                $end = $nextEnd;
            }
        }

        return $end;
    }
}
