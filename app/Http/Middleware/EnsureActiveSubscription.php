<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Services\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Coupe l'accès des utilisateurs dont la boutique n'est plus couverte par un abonnement,
 * y compris pour une session déjà ouverte avant l'échéance.
 */
class EnsureActiveSubscription
{
    public function __construct(protected SubscriptionService $subscriptions)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user) {
            return $next($request);
        }

        $blocking = $this->subscriptions->blockingStatuses($user);
        if ($blocking) {
            return $this->subscriptions->expiredResponse($blocking);
        }

        // Un propriétaire garde l'accès à ses boutiques couvertes, mais pas aux autres
        if (strtolower($user->role?->name ?? '') === 'owner' && ($storeId = $request->input('store_id'))) {
            $store = Store::where('id', $storeId)
                ->where('company_id', $user->owner?->company?->id)
                ->first();

            if ($store && !$this->subscriptions->isAccessible($store)) {
                return $this->subscriptions->expiredResponse(
                    [$this->subscriptions->statusForStore($store)],
                    'STORE_SUBSCRIPTION_EXPIRED'
                );
            }
        }

        return $next($request);
    }
}
