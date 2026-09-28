<?php

namespace App\Http\Controllers;

use App\Http\Resources\PaymentReceiptResource;
use App\Models\PaymentReceipt;
use App\Services\CompanyStoreResolverService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PaymentReceiptController extends Controller
{
    protected $companyStoreResolver;

    public function __construct(CompanyStoreResolverService $companyStoreResolver)
    {
        $this->companyStoreResolver = $companyStoreResolver;
    }

    /**
     * Liste paginée des paiements de factures.
     * Filtres : recherche texte, date précise (date) ou intervalle (start_date / end_date).
     */
    public function index(Request $request)
    {
        try {
            $validator = $this->validateFilters($request);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $storeId = $this->resolveStoreId($request);

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Impossible de déterminer la boutique associée à cet utilisateur.'
                ], 422);
            }

            // Le front envoie per_page, d'autres appelants perPage : on accepte les deux.
            $perPage = (int) ($request->input('perPage') ?? $request->input('per_page') ?? 10);
            $page = (int) $request->input('page', 1);

            $payments = $this->buildQuery($request, $storeId)
                ->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'status' => true,
                'data' => PaymentReceiptResource::collection($payments),
                'totalAmount' => (int) $this->buildQuery($request, $storeId)->sum('payment_receipts.amount'),
                'meta' => [
                    'current_page' => $payments->currentPage(),
                    'per_page' => $payments->perPage(),
                    'total' => $payments->total(),
                    'last_page' => $payments->lastPage(),
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Erreur dans index (PaymentReceiptController) : ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la récupération des paiements.'
            ], 500);
        }
    }

    /**
     * Export PDF de la liste des paiements (mêmes filtres que index, sans pagination).
     */
    public function exportPdf(Request $request)
    {
        try {
            $validator = $this->validateFilters($request);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $storeId = $this->resolveStoreId($request);

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Impossible de déterminer la boutique associée à cet utilisateur.'
                ], 422);
            }

            $payments = $this->buildQuery($request, $storeId)->get();

            $pdf = Pdf::loadView('pdf.payments', [
                'payments' => $payments,
                'store' => \App\Models\Store::find($storeId),
                'totalAmount' => (int) $payments->sum('amount'),
                'period' => $this->describePeriod($request),
                'generatedAt' => Carbon::now(),
            ])->setPaper('a4', 'portrait');

            return $pdf->download('paiements-' . Carbon::now()->format('Y-m-d-His') . '.pdf');
        } catch (\Exception $e) {
            Log::error('Erreur dans exportPdf (PaymentReceiptController) : ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la génération du PDF.'
            ], 500);
        }
    }

    /**
     * Requête commune à la liste et à l'export : scope boutique, filtres, tri.
     */
    private function buildQuery(Request $request, int $storeId): Builder
    {
        $search = $request->input('search');
        $date = $request->input('date');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $paymentType = $request->input('payment_type');

        // payment_receipts n'a pas de store_id : on passe par la facture.
        $query = PaymentReceipt::query()
            ->with(['invoice.customer', 'invoice.sale.customer', 'user'])
            ->whereHas('invoice', fn($q) => $q->where('store_id', $storeId));

        // Un Seller ne voit que les paiements des factures issues de ses propres ventes.
        $user = Auth::user();
        if ($user && $user->role->name == 'Seller') {
            $query->whereHas('invoice.sale', fn($q) => $q->where('seller_id', $user->id));
        }

        $query->when($search, function ($query) use ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('payment_receipts.payment_type', 'ilike', "%$search%")
                    ->orWhere('payment_receipts.amount', 'ilike', "%$search%")
                    ->orWhereHas('invoice', function ($q) use ($search) {
                        $q->where('invoice_number', 'ilike', "%$search%")
                            ->orWhere('customer_name', 'ilike', "%$search%");
                    })
                    ->orWhereHas('invoice.customer', fn($q) => $q->where('name', 'ilike', "%$search%"))
                    ->orWhereHas('invoice.sale.customer', fn($q) => $q->where('name', 'ilike', "%$search%"));
            });
        });

        // Date précise : prioritaire sur l'intervalle.
        if ($date) {
            $query->whereDate('payment_receipts.date', $date);
        } else {
            $query->when($startDate, fn($q) => $q->whereDate('payment_receipts.date', '>=', $startDate))
                ->when($endDate, fn($q) => $q->whereDate('payment_receipts.date', '<=', $endDate));
        }

        $query->when($paymentType, fn($q) => $q->where('payment_receipts.payment_type', $paymentType));

        return $query->orderBy('payment_receipts.created_at', 'desc');
    }

    private function validateFilters(Request $request)
    {
        return Validator::make($request->all(), [
            'search' => 'nullable|string|max:255',
            'date' => 'nullable|date',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'payment_type' => 'nullable|string|in:cash,OM,wave,other',
            'perPage' => 'nullable|integer|min:1|max:100',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ], [
            'date.date' => 'La date doit être une date valide.',
            'start_date.date' => 'La date de début doit être une date valide.',
            'end_date.date' => 'La date de fin doit être une date valide.',
            'end_date.after_or_equal' => 'La date de fin doit être postérieure ou égale à la date de début.',
            'payment_type.in' => 'Le type de paiement est invalide.',
            'perPage.max' => 'Le nombre d\'éléments par page ne peut pas dépasser 100.',
        ]);
    }

    private function resolveStoreId(Request $request): ?int
    {
        $context = $this->companyStoreResolver->resolveStoreAndCompany($request);

        return $context['store_id'] ? (int) $context['store_id'] : null;
    }

    private function describePeriod(Request $request): string
    {
        if ($date = $request->input('date')) {
            return 'Le ' . Carbon::parse($date)->format('d/m/Y');
        }

        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        if ($startDate && $endDate) {
            return 'Du ' . Carbon::parse($startDate)->format('d/m/Y') . ' au ' . Carbon::parse($endDate)->format('d/m/Y');
        }

        if ($startDate) {
            return 'À partir du ' . Carbon::parse($startDate)->format('d/m/Y');
        }

        if ($endDate) {
            return 'Jusqu\'au ' . Carbon::parse($endDate)->format('d/m/Y');
        }

        return 'Toutes les périodes';
    }
}
