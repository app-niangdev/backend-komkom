<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Périmètre de données d'un propriétaire : les boutiques de SON entreprise uniquement.
 * Un gérant (Manager) réutilise les mêmes écrans, limités à SA boutique.
 * Un vendeur (Seller) aussi, limité à SA boutique et à SES ventes (voir sellerId()).
 *
 * - store_id fourni : la boutique doit appartenir à l'entreprise (sinon 404) ;
 *   le middleware d'abonnement a déjà refusé une boutique expirée.
 * - store_id absent (« Toutes les boutiques ») : seules les boutiques couvertes par un
 *   abonnement sont agrégées ; les autres sont signalées dans `excluded`.
 */
class OwnerScopeService
{
    public function __construct(protected SubscriptionService $subscriptions)
    {
    }

    public function isManager(User $user): bool
    {
        return $user->role?->name === 'Manager';
    }

    public function isSeller(User $user): bool
    {
        return $user->role?->name === 'Seller';
    }

    /** Un seul magasin : gérant ou vendeur. */
    public function isStoreStaff(User $user): bool
    {
        return $this->isManager($user) || $this->isSeller($user);
    }

    /**
     * Vendeur connecté : ses ventes, factures et encaissements seulement.
     * Null pour le propriétaire et le gérant (toute la boutique).
     */
    public function sellerId(User $user): ?int
    {
        return $this->isSeller($user) ? $user->id : null;
    }

    public function company(User $user): Company
    {
        $company = $this->isStoreStaff($user) ? $this->staffStore($user)?->company : $user->owner?->company;
        if (!$company) {
            throw new NotFoundHttpException('Aucune entreprise n\'est rattachée à ce compte.');
        }

        return $company;
    }

    /** @return Collection<int, Store> */
    public function stores(User $user): Collection
    {
        if ($this->isStoreStaff($user)) {
            $store = $this->staffStore($user);
            if (!$store) {
                throw new NotFoundHttpException('Aucune boutique n\'est rattachée à ce compte.');
            }

            return new EloquentCollection([$store->load(['subscriptions', 'company'])]);
        }

        return $this->company($user)
            ->stores()
            ->with(['subscriptions', 'company'])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array{store_ids: list<int>, selected: ?Store, excluded: list<array>}
     */
    public function resolve(Request $request): array
    {
        $stores = $this->stores($request->user());

        if ($storeId = $request->input('store_id')) {
            $selected = $stores->firstWhere('id', (int) $storeId);
            if (!$selected) {
                throw new NotFoundHttpException('Boutique introuvable.');
            }

            return ['store_ids' => [$selected->id], 'selected' => $selected, 'excluded' => []];
        }

        $accessible = $stores->filter(fn (Store $s) => $this->subscriptions->isAccessible($s));

        return [
            'store_ids' => $accessible->pluck('id')->values()->all(),
            'selected' => null,
            'excluded' => $stores
                ->reject(fn (Store $s) => $accessible->contains('id', $s->id))
                ->map(fn (Store $s) => $this->subscriptions->statusForStore($s))
                ->values()
                ->all(),
        ];
    }

    private function staffStore(User $user): ?Store
    {
        $profile = $this->isSeller($user) ? $user->seller : $user->manager;

        return $profile?->store ?? $user->managedStore;
    }
}
