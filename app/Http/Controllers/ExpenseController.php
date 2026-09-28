<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Services\CompanyStoreResolverService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ExpenseController extends Controller
{
    protected $companyStoreResolver;

    public function __construct(CompanyStoreResolverService $companyStoreResolver)
    {
        $this->companyStoreResolver = $companyStoreResolver;
    }

    public function index(Request $request)
    {
        $perPage = $request->input('perPage', 10);
        $page = $request->input('page', 1);
        $search = $request->input('search', '');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $context = $this->companyStoreResolver->resolveStoreAndCompany($request);
        $storeId = $context['store_id'];

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Impossible de déterminer la boutique associée à cet utilisateur.'
            ], 422);
        }

        $query = Expense::query()->where('store_id', $storeId);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'Ilike', "%$search%")
                ->orWhere('description', 'Ilike', "%$search%")
                ->orWhere('amount', 'Ilike', "%$search%");
            });
        }

        // Filtrage par dates
        if ($startDate) {
            $query->whereDate('expense_date', '>=', $startDate);
        }

        if ($endDate) {
            $query->whereDate('expense_date', '<=', $endDate);
        }

        $expenses = $query->orderByDesc('created_at')
                        ->paginate($perPage, ['*'], 'page', $page);

        $today = Carbon::today()->toDateString();

        $todayTotal = Expense::where('store_id', $storeId)
            ->whereDate('expense_date', $today)
            ->sum('amount');

        return response()->json([
            'status' => true,
            'data' => $expenses->items(),
            'todayTotal' => $todayTotal,
            'today' => $today,
            'meta' => [
                'current_page' => $expenses->currentPage(),
                'per_page' => $expenses->perPage(),
                'total' => $expenses->total(),
                'last_page' => $expenses->lastPage(),
            ]
        ], 200);
    }

    public function store(Request $request)
    {
        try {
            $context = $this->companyStoreResolver->resolveStoreAndCompany($request);
            $storeId = $context['store_id'];

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Impossible de déterminer la boutique associée à cet utilisateur.'
                ], 422);
            }

            $messages = [
                'title.required' => 'Le titre est obligatoire.',
                'title.string' => 'Le titre doit être une chaîne de caractères.',
                'title.max' => 'Le titre ne peut pas dépasser 255 caractères.',
                'description.string' => 'La description doit être une chaîne de caractères.',
                'amount.required' => 'Le montant est obligatoire.',
                'amount.numeric' => 'Le montant doit être un nombre.',
                'amount.min' => 'Le montant doit être supérieur ou égal à 0.',
                'expense_date.required' => 'La date de dépense est obligatoire.',
            ];

            $validator = Validator::make($request->all(), [
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'amount' => 'required|numeric|min:0',
                'expense_date' => 'required',
            ], $messages);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();

            DB::beginTransaction();

            $expense = Expense::create([
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'amount' => $validated['amount'],
                'expense_date' => $validated['expense_date'],
                'user_id' => Auth::id(),
                'store_id' => $storeId,
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Dépense créée avec succès.',
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur dans ExpenseController@store : ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la création de la dépense.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $context = $this->companyStoreResolver->resolveStoreAndCompany($request);
            $storeId = $context['store_id'];

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Impossible de déterminer la boutique associée à cet utilisateur.'
                ], 422);
            }

            $expense = Expense::where('id', $id)
                ->where('store_id', $storeId)
                ->firstOrFail();

            $messages = [
                'title.string' => 'Le titre doit être une chaîne de caractères.',
                'title.max' => 'Le titre ne peut pas dépasser 255 caractères.',
                'description.string' => 'La description doit être une chaîne de caractères.',
                'amount.numeric' => 'Le montant doit être un nombre.',
                'amount.min' => 'Le montant doit être supérieur ou égal à 0.',
            ];

            $validator = Validator::make($request->all(), [
                'title' => 'sometimes|string|max:255',
                'description' => 'nullable|string',
                'amount' => 'sometimes|numeric|min:0',
                'expense_date' => 'sometimes',
            ], $messages);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();

            DB::beginTransaction();

            $expense->update($validated);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Dépense mise à jour avec succès.',
                'data' => $expense->fresh()
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Dépense introuvable.'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur dans ExpenseController@update : ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la mise à jour de la dépense.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function destroy(Request $request, $id)
    {
        $expense = Expense::findOrFail($id);

        if (Auth::id() !== $expense->user_id) {
            return response()->json([
                'status' => false,
                'message' => "Vous n'avez pas le droit de supprimer cette dépense."
            ], 403);
        }

        $expense->delete();

        return response()->json([
            'status' => true,
            'message' => 'Dépense supprimée avec succès.'
        ]);
    }

}
