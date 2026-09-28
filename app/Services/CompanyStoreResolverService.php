<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CompanyStoreResolverService
{
    /**
     * Résout le store_id et le company_id selon le rôle de l'utilisateur.
     *
     * @param Request $request
     * @return array{store_id: int|null, company_id: int|null}
     */
    public function resolveStoreAndCompany(Request $request): array
    {
        $user = Auth::user();
        if (!$user) {
            return ['store_id' => null, 'company_id' => null];
        }

        $roleName = strtolower($user->role->name);

        switch ($roleName) {
            case 'seller':
                return $this->resolveForSeller($user);

            case 'manager':
                return $this->resolveForManager($user);

            case 'owner':
                return $this->resolveForOwner($user, $request);

            case 'admin':
                return ['store_id' => $request->input('store_id'), 'company_id' => null];

            default:
                return ['store_id' => null, 'company_id' => null];
        }
    }

    private function resolveForSeller($user): array
    {
        $seller = $user->seller;
        Log::info($seller->store);
        if (!$seller || !$seller->store) {
            return ['store_id' => null, 'company_id' => null];
        }

        return [
            'store_id' => $seller->store_id,
            'company_id' => $seller->store->company_id
        ];
    }

    private function resolveForManager($user): array
    {
        $manager = $user->manager;
        Log::info($manager->store);
        if (!$manager || !$manager->store) {
            return ['store_id' => null, 'company_id' => null];
        }

        return [
            'store_id' => $manager->store_id,
            'company_id' => $manager->store->company_id
        ];
    }

    private function resolveForOwner($user, Request $request): array
    {
        $storeId = $request->input('store_id');
        $owner = $user->owner;

        if (!$owner || !$owner->company) {
            return ['store_id' => null, 'company_id' => null];
        }

        // Si aucun store_id n'est fourni, on retourne juste la company
        if (!$storeId) {
            return [
                'store_id' => null,
                'company_id' => $owner->company->id
            ];
        }

        // Vérifie que le store appartient bien à cette company
        $store = $owner->company->stores()->where('id', $storeId)->first();
        if (!$store) {
            return ['store_id' => null, 'company_id' => $owner->company->id];
        }

        return [
            'store_id' => $storeId,
            'company_id' => $owner->company->id
        ];
    }
}
