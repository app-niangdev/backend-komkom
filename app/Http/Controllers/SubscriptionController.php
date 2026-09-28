<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Gestion des abonnements des boutiques (administrateur).
 */
class SubscriptionController extends Controller
{
    public function __construct(protected SubscriptionService $subscriptions)
    {
    }

    /**
     * Boutiques avec leur état d'abonnement (vue d'ensemble).
     */
    public function stores(Request $request)
    {
        $query = Store::with(['company', 'subscriptions'])->orderBy('name');

        if ($companyId = $request->input('company_id')) {
            $query->where('company_id', $companyId);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                    ->orWhereHas('company', fn ($c) => $c->where('name', 'ILIKE', "%{$search}%"));
            });
        }

        $all = $query->get()->map(fn (Store $store) => $this->subscriptions->statusForStore($store) + [
            'store_active' => $store->active,
            'company_id' => $store->company_id,
        ]);

        // Filtre sur l'état calculé (active, expiring, expired, none)
        $statuses = ($state = $request->input('state')) ? $all->where('state', $state) : $all;

        $perPage = (int) $request->input('perPage', 15);
        $page = max(1, (int) $request->input('page', 1));
        $total = $statuses->count();

        return response()->json([
            // Les plus urgents d'abord ; sans abonnement en tête
            'data' => $statuses->sortBy(fn ($s) => $s['days_left'] ?? PHP_INT_MIN)->values()->forPage($page, $perPage)->values(),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
            'counts' => [
                'active' => $all->where('state', SubscriptionService::ACTIVE)->count(),
                'expiring' => $all->where('state', SubscriptionService::EXPIRING)->count(),
                'expired' => $all->where('state', SubscriptionService::EXPIRED)->count(),
                'none' => $all->where('state', SubscriptionService::NONE)->count(),
            ],
        ]);
    }

    /**
     * Historique des abonnements d'une boutique.
     */
    public function index($storeId)
    {
        $store = Store::with(['company', 'subscriptions.creator'])->findOrFail($storeId);

        return response()->json([
            'status' => $this->subscriptions->statusForStore($store),
            'data' => $store->subscriptions->sortByDesc('starts_at')->values(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);

        if ($conflict = $this->overlapping($validated)) {
            return $this->overlapResponse($conflict);
        }

        $subscription = Subscription::create($validated + ['created_by' => $request->user()->id]);

        return response()->json([
            'success' => true,
            'message' => 'Abonnement enregistré avec succès.',
            'data' => $subscription,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $subscription = Subscription::findOrFail($id);
        $validated = $this->validatePayload($request);

        if ($conflict = $this->overlapping($validated, $subscription->id)) {
            return $this->overlapResponse($conflict);
        }

        $subscription->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Abonnement modifié avec succès.',
            'data' => $subscription,
        ]);
    }

    public function destroy($id)
    {
        Subscription::findOrFail($id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Abonnement supprimé avec succès.',
        ]);
    }

    private function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'store_id' => ['required', Rule::exists('stores', 'id')->whereNull('deleted_at')],
            'plan' => 'required|string|max:100',
            'amount' => 'required|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after_or_equal:starts_at',
            'notes' => 'nullable|string|max:1000',
        ], [
            'store_id.required' => 'La boutique est obligatoire.',
            'store_id.exists' => 'La boutique sélectionnée est invalide.',
            'plan.required' => 'La formule est obligatoire.',
            'amount.required' => 'Le montant est obligatoire.',
            'amount.min' => 'Le montant ne peut pas être négatif.',
            'starts_at.required' => 'La date de début est obligatoire.',
            'ends_at.required' => 'La date de fin est obligatoire.',
            'ends_at.after_or_equal' => 'La date de fin doit être postérieure ou égale à la date de début.',
        ]);

        $validated['currency'] = strtoupper($validated['currency'] ?? config('subscriptions.default_currency', 'XOF'));

        return $validated;
    }

    /**
     * Deux abonnements d'une même boutique ne peuvent pas se chevaucher.
     */
    private function overlapping(array $data, ?int $ignoreId = null): ?Subscription
    {
        return Subscription::where('store_id', $data['store_id'])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->whereDate('starts_at', '<=', $data['ends_at'])
            ->whereDate('ends_at', '>=', $data['starts_at'])
            ->first();
    }

    private function overlapResponse(Subscription $conflict)
    {
        return response()->json([
            'success' => false,
            'message' => sprintf(
                'Cette période chevauche l\'abonnement « %s » du %s au %s.',
                $conflict->plan,
                $conflict->starts_at->format('d/m/Y'),
                $conflict->ends_at->format('d/m/Y')
            ),
        ], 422);
    }
}
