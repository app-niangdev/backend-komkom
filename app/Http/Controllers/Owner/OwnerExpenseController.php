<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Services\OwnerScopeService;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;

/**
 * Dépenses des boutiques du propriétaire : suivi sur une période et gestion.
 */
class OwnerExpenseController extends Controller
{
    public function __construct(protected OwnerScopeService $scope)
    {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'start' => 'required|date',
            'end' => 'required|date|after_or_equal:start',
            'search' => 'nullable|string|max:100',
            'sort' => 'nullable|in:recent,amount',
            'perPage' => 'nullable|integer|min:1|max:100',
        ]);

        $storeIds = $this->scope->resolve($request)['store_ids'];
        $start = CarbonImmutable::parse($validated['start'])->startOfDay();
        $end = CarbonImmutable::parse($validated['end'])->startOfDay();
        $days = (int) $start->diffInDays($end) + 1;
        $previousEnd = $start->subDay();
        $previousStart = $previousEnd->subDays($days - 1);

        $inPeriod = fn ($from, $to) => Expense::query()
            ->whereIn('expenses.store_id', $storeIds)
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()]);

        $search = $validated['search'] ?? null;
        $filtered = $inPeriod($start, $end)->when($search, fn ($q, $s) => $q->where(fn ($sub) => $sub
            ->where('title', 'ILIKE', "%{$s}%")
            ->orWhere('description', 'ILIKE', "%{$s}%")));

        $total = (clone $filtered)->sum('amount');
        $count = (clone $filtered)->count();
        $previousTotal = $inPeriod($previousStart, $previousEnd)
            ->when($search, fn ($q, $s) => $q->where(fn ($sub) => $sub
                ->where('title', 'ILIKE', "%{$s}%")
                ->orWhere('description', 'ILIKE', "%{$s}%")))
            ->sum('amount');

        // Postes de dépense : regroupement par intitulé (casse et espaces ignorés)
        $byTitle = (clone $filtered)
            ->selectRaw('MIN(title) AS title, SUM(amount) AS total, COUNT(*) AS n')
            ->groupByRaw('LOWER(TRIM(title))')
            ->orderByDesc('total')
            ->limit(6)
            ->get();

        $byStore = (clone $filtered)
            ->join('stores', 'stores.id', '=', 'expenses.store_id')
            ->groupBy('stores.id', 'stores.name')
            ->selectRaw('stores.id AS store_id, stores.name AS store_name, SUM(expenses.amount) AS total, COUNT(*) AS n')
            ->orderByDesc('total')
            ->get();

        $expenses = (clone $filtered)
            ->with(['store:id,name', 'user:id,first_name,last_name'])
            ->when(($validated['sort'] ?? 'recent') === 'amount',
                fn ($q) => $q->orderByDesc('amount'),
                fn ($q) => $q->orderByDesc('expense_date'))
            ->orderByDesc('id')
            ->paginate($validated['perPage'] ?? 20);

        return response()->json([
            'data' => collect($expenses->items())->map(fn (Expense $e) => $this->present($e)),
            'meta' => [
                'current_page' => $expenses->currentPage(),
                'per_page' => $expenses->perPage(),
                'total' => $expenses->total(),
                'last_page' => $expenses->lastPage(),
            ],
            'summary' => [
                'total' => (float) $total,
                'previous_total' => (float) $previousTotal,
                'count' => $count,
                'daily_average' => round($total / max(1, $days)),
                'largest' => (float) ((clone $filtered)->max('amount') ?? 0),
                'by_title' => $byTitle->map(fn ($r) => [
                    'title' => $r->title,
                    'total' => (float) $r->total,
                    'count' => (int) $r->n,
                ]),
                'by_store' => $byStore->map(fn ($r) => [
                    'store_id' => $r->store_id,
                    'store_name' => $r->store_name,
                    'total' => (float) $r->total,
                    'count' => (int) $r->n,
                ]),
            ],
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'days' => $days,
                'previous_start' => $previousStart->toDateString(),
                'previous_end' => $previousEnd->toDateString(),
                'granularity' => $this->granularity($days),
            ],
            'series' => $this->series(clone $filtered, $start, $end, $this->granularity($days)),
            'currency' => config('subscriptions.default_currency', 'XOF'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);
        $this->assertOwnedStore($request, $validated['store_id']);

        $expense = Expense::create($validated + ['user_id' => $request->user()->id]);

        return response()->json([
            'success' => true,
            'message' => 'Dépense « ' . $expense->title . ' » enregistrée.',
            'data' => $this->present($expense->load(['store:id,name', 'user:id,first_name,last_name'])),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $expense = $this->find($request, $id);
        $validated = $this->validatePayload($request);
        $this->assertOwnedStore($request, $validated['store_id']);

        $expense->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Dépense mise à jour.',
            'data' => $this->present($expense->fresh()->load(['store:id,name', 'user:id,first_name,last_name'])),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $this->find($request, $id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Dépense supprimée.',
        ]);
    }

    private function granularity(int $days): string
    {
        return match (true) {
            $days <= 45 => 'day',
            $days <= 190 => 'week',
            default => 'month',
        };
    }

    private function series($query, CarbonImmutable $start, CarbonImmutable $end, string $granularity): array
    {
        $rows = $query
            ->selectRaw("DATE(DATE_TRUNC('{$granularity}', expense_date)) AS bucket, SUM(amount) AS value")
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

    /** Dépense d'une boutique de l'entreprise, sinon 404. */
    private function find(Request $request, $id): Expense
    {
        $storeIds = $this->scope->stores($request->user())->pluck('id');

        return Expense::whereIn('store_id', $storeIds)->findOrFail($id);
    }

    private function assertOwnedStore(Request $request, $storeId): void
    {
        $owned = $this->scope->stores($request->user())->contains('id', (int) $storeId);
        abort_if(!$owned, 422, 'La boutique choisie ne fait pas partie de vos boutiques.');
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'store_id' => 'required|integer',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'amount' => 'required|integer|min:1',
            'expense_date' => 'required|date|before_or_equal:today',
        ], [
            'store_id.required' => 'La boutique est obligatoire.',
            'title.required' => 'L\'intitulé est obligatoire.',
            'amount.required' => 'Le montant est obligatoire.',
            'amount.integer' => 'Le montant doit être un nombre entier.',
            'amount.min' => 'Le montant doit être supérieur à 0.',
            'expense_date.required' => 'La date est obligatoire.',
            'expense_date.before_or_equal' => 'La date ne peut pas être dans le futur.',
        ]);
    }

    private function present(Expense $e): array
    {
        return [
            'id' => $e->id,
            'title' => $e->title,
            'description' => $e->description,
            'amount' => (float) $e->amount,
            'date' => substr((string) $e->expense_date, 0, 10),
            'store' => $e->store ? ['id' => $e->store->id, 'name' => $e->store->name] : null,
            'user' => $e->user ? trim($e->user->first_name . ' ' . $e->user->last_name) : null,
        ];
    }
}
