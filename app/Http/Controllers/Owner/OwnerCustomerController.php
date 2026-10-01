<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Sale;
use App\Models\Store;
use App\Services\DebtReminderService;
use App\Services\OwnerScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Clients des boutiques du propriétaire : liste, fiche détaillée, gestion et relance
 * WhatsApp des débiteurs.
 */
class OwnerCustomerController extends Controller
{
    /** Colonnes de la boutique utiles à la présentation d'un client (dont l'activation des relances). */
    private const STORE_COLUMNS = 'store:id,name,whatsapp_invoices_enabled';

    public function __construct(protected OwnerScopeService $scope, protected DebtReminderService $reminders)
    {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:100',
            'segment' => 'nullable|in:debtors,buyers,inactive',
            'sort' => 'nullable|in:purchases,debt,recent,name',
            'perPage' => 'nullable|integer|min:1|max:100',
        ]);

        $storeIds = $this->scope->resolve($request)['store_ids'];

        $base = $this->withStats(Customer::query()->whereIn('customers.store_id', $storeIds));

        // Synthèse du périmètre (indépendante des filtres)
        $summary = DB::query()->fromSub(clone $base, 'c')->selectRaw('
            COUNT(*) AS customers,
            COUNT(*) FILTER (WHERE COALESCE(balance_due, 0) > 0) AS debtors,
            COALESCE(SUM(balance_due), 0) AS total_due,
            COALESCE(SUM(total_purchases), 0) AS total_purchases,
            COUNT(*) FILTER (WHERE COALESCE(sales_count, 0) = 0) AS inactive
        ')->first();

        $query = (clone $base)
            ->when($validated['search'] ?? null, fn ($q, $s) => $q->where(fn ($sub) => $sub
                ->where('customers.name', 'ILIKE', "%{$s}%")
                ->orWhere('customers.phone', 'ILIKE', "%{$s}%")
                ->orWhere('customers.email', 'ILIKE', "%{$s}%")
                ->orWhere('customers.address', 'ILIKE', "%{$s}%")))
            ->when($validated['segment'] ?? null, fn ($q, $segment) => match ($segment) {
                'debtors' => $q->whereRaw('COALESCE(bal.balance_due, 0) > 0'),
                'buyers' => $q->whereRaw('COALESCE(stats.sales_count, 0) > 0'),
                'inactive' => $q->whereRaw('COALESCE(stats.sales_count, 0) = 0'),
            });

        match ($validated['sort'] ?? 'purchases') {
            'debt' => $query->orderByRaw('COALESCE(bal.balance_due, 0) DESC'),
            'recent' => $query->orderByRaw('stats.last_purchase_at DESC NULLS LAST'),
            'name' => $query->orderBy('customers.name'),
            default => $query->orderByRaw('COALESCE(stats.total_purchases, 0) DESC'),
        };

        $customers = $query->orderBy('customers.name')
            ->with(self::STORE_COLUMNS)
            ->paginate($validated['perPage'] ?? 20);

        return response()->json([
            'data' => collect($customers->items())->map(fn ($c) => $this->present($c)),
            'meta' => [
                'current_page' => $customers->currentPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
                'last_page' => $customers->lastPage(),
            ],
            'summary' => [
                'customers' => (int) $summary->customers,
                'debtors' => (int) $summary->debtors,
                'total_due' => (float) $summary->total_due,
                'total_purchases' => (float) $summary->total_purchases,
                'inactive' => (int) $summary->inactive,
            ],
            'currency' => config('subscriptions.default_currency', 'XOF'),
            'reminders_enabled' => $this->remindersEnabled($storeIds),
        ]);
    }

    /**
     * Débiteurs du périmètre, du plus gros reste dû au plus petit, avec ce qui empêche
     * éventuellement de les relancer : sert à préparer une relance groupée.
     */
    public function reminderTargets(Request $request)
    {
        $storeIds = $this->scope->resolve($request)['store_ids'];

        $debtors = $this->withStats(Customer::query()->whereIn('customers.store_id', $storeIds))
            ->whereRaw('COALESCE(bal.balance_due, 0) > 0')
            ->orderByRaw('bal.balance_due DESC')
            ->orderBy('customers.name')
            ->with(self::STORE_COLUMNS)
            ->limit(500)
            ->get();

        return response()->json([
            'data' => $debtors->map(fn ($c) => $this->present($c)),
            'batch_size' => max(1, (int) config('services.waha.reminder_batch_size')),
            'cooldown_hours' => $this->reminders->cooldownHours(),
            'currency' => config('subscriptions.default_currency', 'XOF'),
        ]);
    }

    /**
     * Relance un client débiteur sur WhatsApp. 422 = relance sans objet (rien à payer, numéro
     * inexploitable, déjà relancé…), 502 = l'envoi a échoué (`fatal` : le service est en panne).
     */
    public function remind(Request $request, $id)
    {
        $storeIds = $this->scope->resolve($request->merge(['store_id' => null]))['store_ids'];
        $customer = Customer::with('store')->whereIn('store_id', $storeIds)->findOrFail($id);

        $result = $this->reminders->remind($customer, $request->user());

        return response()->json($result, match ($result['status']) {
            'sent' => 200,
            'skipped' => 422,
            default => 502,
        });
    }

    /**
     * Fiche client : coordonnées, indicateurs, historique d'achats, factures non soldées.
     */
    public function show(Request $request, $id)
    {
        $customer = $this->find($request, $id);
        $withStats = $this->withStats(Customer::query()->where('customers.id', $customer->id))->first();

        $sales = Sale::with(['invoice:id,sale_id,invoice_number,amount_paid,balance', 'store:id,name'])
            ->where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (Sale $s) => [
                'id' => $s->id,
                'sale_number' => $s->sale_number,
                'date' => $s->created_at?->toIso8601String(),
                'total_amount' => (float) $s->total_amount,
                'status' => $s->status,
                'payment_status' => $s->status_payment,
                'balance' => $s->status === 'cancelled' ? 0 : (float) ($s->invoice?->balance ?? 0),
            ]);

        $openInvoices = Invoice::where('customer_id', $customer->id)
            ->where('is_cancelled', false)
            ->where('balance', '>', 0)
            ->orderBy('created_at')
            ->get(['id', 'invoice_number', 'amount_total', 'amount_paid', 'balance', 'created_at'])
            ->map(fn ($i) => [
                'id' => $i->id,
                'number' => $i->invoice_number,
                'date' => $i->created_at?->toDateString(),
                'amount_total' => (float) $i->amount_total,
                'amount_paid' => (float) $i->amount_paid,
                'balance' => (float) $i->balance,
                'age_days' => (int) $i->created_at?->diffInDays(now()),
            ]);

        return response()->json([
            // array_merge (et non +) : les clés de la fiche remplacent celles du résumé (open_invoices = liste)
            'data' => array_merge($this->present($withStats->setRelation('store', $customer->store)), [
                'average_basket' => $withStats->sales_count > 0
                    ? round($withStats->total_purchases / $withStats->sales_count)
                    : 0,
                'first_purchase_at' => $withStats->first_purchase_at ? substr($withStats->first_purchase_at, 0, 10) : null,
                'created_at' => $customer->created_at?->toDateString(),
                'sales' => $sales,
                'open_invoices' => $openInvoices,
            ]),
            'currency' => config('subscriptions.default_currency', 'XOF'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);
        $this->assertOwnedStore($request, $validated['store_id']);

        $customer = Customer::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Client « ' . $customer->name . ' » ajouté.',
            'data' => $this->present($customer->load(self::STORE_COLUMNS)),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $customer = $this->find($request, $id);
        $validated = $this->validatePayload($request);
        $this->assertOwnedStore($request, $validated['store_id']);

        // Un client qui a déjà acheté reste rattaché à sa boutique (cohérence de l'historique)
        if ((int) $validated['store_id'] !== $customer->store_id && Sale::where('customer_id', $customer->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Ce client a déjà des achats dans sa boutique : il ne peut pas être déplacé.',
            ], 422);
        }

        $customer->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Client mis à jour.',
            'data' => $this->present($customer->fresh()->load(self::STORE_COLUMNS)),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $customer = $this->find($request, $id);

        if (Sale::where('customer_id', $customer->id)->exists() || Invoice::where('customer_id', $customer->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Ce client a des ventes ou des factures : il ne peut pas être supprimé.',
            ], 422);
        }

        $customer->delete();

        return response()->json([
            'success' => true,
            'message' => 'Client supprimé.',
        ]);
    }

    /** Ajoute aux clients leurs achats validés, leur reste dû et la date de leur dernière relance. */
    private function withStats($query)
    {
        $salesStats = DB::table('sales')
            ->where('status', 'confirmed')
            ->whereNull('deleted_at')
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->selectRaw('customer_id, COUNT(*) AS sales_count, SUM(total_amount) AS total_purchases,
                MAX(created_at) AS last_purchase_at, MIN(created_at) AS first_purchase_at');

        $balances = DB::table('invoices')
            ->where('is_cancelled', false)
            ->whereNull('deleted_at')
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->selectRaw('customer_id, SUM(balance) AS balance_due, COUNT(*) FILTER (WHERE balance > 0) AS open_invoices');

        $reminders = DB::table('customer_reminders')
            ->where('status', 'sent')
            ->groupBy('customer_id')
            ->selectRaw('customer_id, MAX(created_at) AS last_reminded_at');

        return $query
            ->leftJoinSub($salesStats, 'stats', 'stats.customer_id', '=', 'customers.id')
            ->leftJoinSub($balances, 'bal', 'bal.customer_id', '=', 'customers.id')
            ->leftJoinSub($reminders, 'rem', 'rem.customer_id', '=', 'customers.id')
            ->select(
                'customers.*',
                'stats.sales_count',
                'stats.total_purchases',
                'stats.last_purchase_at',
                'stats.first_purchase_at',
                'bal.balance_due',
                'bal.open_invoices',
                'rem.last_reminded_at'
            );
    }

    /** Client d'une boutique de l'entreprise (toutes boutiques, même expirées : 404 sinon). */
    private function find(Request $request, $id): Customer
    {
        $storeIds = $this->scope->stores($request->user())->pluck('id');

        return Customer::with(self::STORE_COLUMNS)->whereIn('store_id', $storeIds)->findOrFail($id);
    }

    /** Au moins une boutique du périmètre peut relancer ses clients sur WhatsApp. */
    private function remindersEnabled(array $storeIds): bool
    {
        return Store::whereIn('id', $storeIds)->get(['id', 'whatsapp_invoices_enabled'])
            ->contains(fn (Store $s) => $this->reminders->enabledFor($s));
    }

    private function assertOwnedStore(Request $request, $storeId): void
    {
        $owned = $this->scope->stores($request->user())->contains('id', (int) $storeId);
        abort_if(!$owned, 422, 'La boutique choisie ne fait pas partie de vos boutiques.');
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'required|string|max:255',
            'store_id' => 'required|integer',
        ], [
            'name.required' => 'Le nom du client est obligatoire.',
            'phone.required' => 'Le téléphone est obligatoire.',
            'phone.max' => 'Le téléphone ne doit pas dépasser 20 caractères.',
            'email.email' => 'L\'email est invalide.',
            'address.required' => 'L\'adresse est obligatoire.',
            'store_id.required' => 'La boutique est obligatoire.',
        ]);
    }

    private function present($c): array
    {
        $balance = (float) ($c->balance_due ?? 0);
        $lastReminder = $c->last_reminded_at ? Carbon::parse($c->last_reminded_at) : null;

        return [
            'id' => $c->id,
            'name' => $c->name,
            'phone' => $c->phone,
            'email' => $c->email,
            'address' => $c->address,
            'store' => $c->store ? ['id' => $c->store->id, 'name' => $c->store->name] : null,
            'sales_count' => (int) ($c->sales_count ?? 0),
            'total_purchases' => (float) ($c->total_purchases ?? 0),
            'balance_due' => $balance,
            'open_invoices' => (int) ($c->open_invoices ?? 0),
            'last_purchase_at' => $c->last_purchase_at ? substr($c->last_purchase_at, 0, 10) : null,
            // Relance WhatsApp : `blocker` null = le client peut être relancé maintenant
            'reminder' => [
                'blocker' => $this->reminders->blocker($c->store, $c->phone, $balance, $lastReminder),
                'last_at' => $lastReminder?->toIso8601String(),
            ],
        ];
    }
}
