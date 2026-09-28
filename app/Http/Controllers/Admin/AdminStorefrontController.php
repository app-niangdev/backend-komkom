<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\StorefrontService;
use Illuminate\Http\Request;

/**
 * Vitrines publiques des boutiques : seul l'administrateur les active et choisit leur lien.
 */
class AdminStorefrontController extends Controller
{
    public function __construct(protected StorefrontService $storefront)
    {
    }

    /** Boutiques d'une entreprise avec l'état de leur vitrine. */
    public function index(Request $request)
    {
        $validated = $request->validate(['company_id' => 'required|integer|exists:companies,id']);

        $stores = Store::with(['subscriptions', 'company'])
            ->where('company_id', $validated['company_id'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $stores->map(fn (Store $s) => $this->present($s)),
        ]);
    }

    /** Lien proposé pour une boutique (avant activation) et adresse de la vitrine, pour l'aperçu. */
    public function suggest(Request $request, $storeId)
    {
        $store = Store::findOrFail($storeId);

        return response()->json(['data' => [
            'slug' => $store->slug ?? $this->storefront->suggestSlug($store->name, $store),
            'base_url' => config('storefront.url'),
        ]]);
    }

    public function update(Request $request, $storeId)
    {
        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'slug' => 'nullable|string|max:60',
        ]);
        $store = Store::with(['subscriptions', 'company'])->findOrFail($storeId);

        $this->storefront->configure($store, $validated['enabled'], $validated['slug'] ?? null);

        return response()->json([
            'success' => true,
            'message' => $store->storefront_enabled
                ? "Vitrine de « {$store->name} » activée."
                : "Vitrine de « {$store->name} » désactivée.",
            'data' => $this->present($store),
        ]);
    }

    private function present(Store $store): array
    {
        return [
            'id' => $store->id,
            'name' => $store->name,
            'phone_one' => $store->phone_one,
            'active' => (bool) $store->active,
            'storefront' => $this->storefront->status($store),
        ];
    }
}
