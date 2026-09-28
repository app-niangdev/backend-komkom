<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Supplierproduct;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ReportingService
{
    protected $companyStoreResolver;

    public function __construct(CompanyStoreResolverService $companyStoreResolver)
    {
        $this->companyStoreResolver = $companyStoreResolver;
    }

    public function getYearlyInvoiceSummary(Request $request, ?int $year = null, ?string $startDate = null, ?string $endDate = null)
    {
        // Si startDate et endDate sont fournis, on utilise ces dates
        if ($startDate && $endDate) {
            return $this->getCustomDateRangeSummary($request, $startDate, $endDate);
        }

        // Sinon, on utilise l'année (courante par défaut)
        if ($year === null) {
            $year = date('Y');
        }

        $summaryMonths = [];

        for ($month = 1; $month <= 12; $month++) {
            $summaryMonths[] = $this->getMonthlyInvoiceData($request, $year, $month);
        }

        return [
            'summary_type' => 'yearly',
            'year' => $year,
            'summary_data' => $summaryMonths
        ];
    }

    public function getCustomDateRangeSummary(Request $request, string $startDate, string $endDate)
    {
        $context = $this->companyStoreResolver->resolveStoreAndCompany($request);
        $storeId = $context['store_id'];

        if (!$storeId) {
            return [
                'summary_type' => 'custom',
                'start_date' => $startDate,
                'end_date' => $endDate,
                'summary_data' => []
            ];
        }

        // Valider les dates
        $startDate = Carbon::parse($startDate)->startOfDay();
        $endDate = Carbon::parse($endDate)->endOfDay();

        // Si la période est supérieure à 1 an, on groupe par mois
        $monthsDiff = $startDate->diffInMonths($endDate);

        if ($monthsDiff > 12) {
            return [
                'summary_type' => 'custom',
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
                'summary_data' => [],
                'error' => 'La période ne peut pas dépasser 12 mois'
            ];
        }

        if ($monthsDiff > 1) {
            // Grouper par mois pour les périodes longues
            return $this->getGroupedByMonthSummary($request, $startDate, $endDate);
        } else {
            // Pour les périodes courtes, retourner les données brutes
            return $this->getDirectDateRangeSummary($request, $startDate, $endDate);
        }
    }

    private function getGroupedByMonthSummary(Request $request, Carbon $startDate, Carbon $endDate)
    {
        $context = $this->companyStoreResolver->resolveStoreAndCompany($request);
        $storeId = $context['store_id'];

        if (!$storeId) {
            return [];
        }

        $frenchMonths = [
            1 => 'Janvier', 2 => 'Février', 3 => 'Mars',
            4 => 'Avril', 5 => 'Mai', 6 => 'Juin',
            7 => 'Juillet', 8 => 'Août', 9 => 'Septembre',
            10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
        ];

        $summaryMonths = [];
        $currentDate = $startDate->copy();

        while ($currentDate <= $endDate) {
            $year = $currentDate->year;
            $month = $currentDate->month;

            $monthStart = $currentDate->copy()->startOfMonth();
            $monthEnd = $currentDate->copy()->endOfMonth();

            // Ajuster les dates de début/fin pour ne pas dépasser la période demandée
            $queryStart = $monthStart->max($startDate);
            $queryEnd = $monthEnd->min($endDate);

            $invoices = Invoice::where('store_id', $storeId)
                ->whereBetween('created_at', [$queryStart, $queryEnd])
                ->get();

            $monthData = $this->calculateInvoiceStats($invoices);

            $summaryMonths[] = [
                'period' => $frenchMonths[$month] . ' ' . $year,
                'year' => $year,
                'month' => $month,
                'start_date' => $queryStart->format('Y-m-d'),
                'end_date' => $queryEnd->format('Y-m-d'),
                ...$monthData
            ];

            $currentDate->addMonth()->startOfMonth();
        }

        return [
            'summary_type' => 'custom_grouped',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'summary_data' => $summaryMonths
        ];
    }

    private function getDirectDateRangeSummary(Request $request, Carbon $startDate, Carbon $endDate)
    {
        $context = $this->companyStoreResolver->resolveStoreAndCompany($request);
        $storeId = $context['store_id'];

        if (!$storeId) {
            return [];
        }

        $invoices = Invoice::where('store_id', $storeId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        $stats = $this->calculateInvoiceStats($invoices);

        return [
            'summary_type' => 'custom_direct',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'summary_data' => [
                'period' => $startDate->format('d/m/Y') . ' - ' . $endDate->format('d/m/Y'),
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
                ...$stats
            ]
        ];
    }

    private function calculateInvoiceStats($invoices)
    {
        $totalAmount = 0;
        $totalPaid = 0;
        $totalUnpaid = 0;
        $totalCancel = 0;
        $invoiceCount = 0;
        $activeInvoiceCount = 0; // Compteur pour les factures actives (non annulées)

        foreach ($invoices as $invoice) {
            $invoiceCount++;

            // Vérifier si la facture est annulée
            $isCancelled = $invoice->is_cancelled || $invoice->invoice_status === 'cancelled';

            if ($isCancelled) {
                // Pour les factures annulées, ajouter seulement au total des annulations
                $totalCancel += $invoice->amount_total;
                continue; // Passer à la facture suivante sans inclure dans les autres totaux
            }

            // Seulement les factures NON annulées sont incluses dans ces totaux
            $activeInvoiceCount++;
            $totalAmount += $invoice->amount_total;
            $totalPaid += $invoice->amount_paid;
            $totalUnpaid += ($invoice->amount_total - $invoice->amount_paid);
        }

        return [
            'invoice_count' => $invoiceCount, // Toutes les factures
            'active_invoice_count' => $activeInvoiceCount, // Factures non annulées
            'cancelled_invoice_count' => $invoiceCount - $activeInvoiceCount, // Factures annulées
            'total_amount' => $totalAmount,
            'total_paid' => $totalPaid,
            'total_unpaid' => $totalUnpaid,
            'total_cancel' => $totalCancel,
            'payment_rate' => $totalAmount > 0 ? round(($totalPaid / $totalAmount) * 100, 2) : 0
        ];
    }

    // Modifiez vos méthodes pour utiliser la bonne version
    private function getMonthlyInvoiceData(Request $request, int $year, int $monthNumber)
    {
        $context = $this->companyStoreResolver->resolveStoreAndCompany($request);
        $storeId = $context['store_id'];

        if (!$storeId) {
            return [
                'month' => $this->getFrenchMonthName($monthNumber),
                'year' => $year,
                'total_amount' => 0,
                'total_paid' => 0,
                'total_unpaid' => 0,
                'total_cancel' => 0
            ];
        }

        $startDate = sprintf('%04d-%02d-01', $year, $monthNumber);
        $endDate = date('Y-m-t', strtotime($startDate));

        $invoices = Invoice::where('store_id', $storeId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        $stats = $this->calculateInvoiceStats($invoices);

        return [
            'month' => $this->getFrenchMonthName($monthNumber),
            'year' => $year,
            'start_date' => $startDate,
            'end_date' => $endDate,
            ...$stats
        ];
    }

    private function getFrenchMonthName(int $monthNumber): string
    {
        $frenchMonths = [
            1 => 'Janvier', 2 => 'Février', 3 => 'Mars',
            4 => 'Avril', 5 => 'Mai', 6 => 'Juin',
            7 => 'Juillet', 8 => 'Août', 9 => 'Septembre',
            10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
        ];

        return $frenchMonths[$monthNumber] ?? 'Inconnu';
    }


    // ==========


    public function getFixData(Request $request)
    {
        $context = $this->companyStoreResolver->resolveStoreAndCompany($request);
        $storeId = $context['store_id'];

        if (!$storeId) {
            return [
                'error' => 'Impossible de déterminer la boutique'
            ];
        }

        // Récupérer les paramètres de date optionnels
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        // Exécuter toutes les requêtes en parallèle pour optimiser les performances
        $results = $this->executeParallelQueries($storeId, $startDate, $endDate);

        return [
            'nbProduct' => $results['products_count'] ?? 0,
            'nbCustomer' => $results['customers_count'] ?? 0,
            'nbSupplier' => $results['suppliers_count'] ?? 0,
            'nbInvoices' => $results['total_invoices_count'] ?? 0,
            'nbInvoiceNoPaid' => $results['invoices_no_paid'] ?? 0,
            'nbInvoicePaid' => $results['invoices_paid'] ?? 0,
            'nbInvoicePartial' => $results['invoices_partial'] ?? 0,
            'nbInvoicePending' => $results['invoices_pending'] ?? 0,
            'nbInvoiceCancel' => $results['invoices_cancelled'] ?? 0,
            'sales_summary' => $results['sales_summary'] ?? [
                'confirmed' => 0,
                'pending' => 0,
                'cancelled' => 0
            ],
            'store_id' => $storeId,
            'timestamp' => now()->toDateTimeString(),
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate
            ]
        ];
    }

    private function executeParallelQueries($storeId, $startDate = null, $endDate = null)
    {
        // Utilisation de plusieurs requêtes en parallèle
        $results = [];

        try {
            // Requête 1: Produits (pas de filtre de date car ce sont des données de stock)
            $results['products_count'] = Product::where('store_id', $storeId)->count();

            // Requête 2: Clients (pas de filtre de date car ce sont des données de base)
            $results['customers_count'] = Customer::where('store_id', $storeId)->count();

            // Requête 3: Fournisseurs (pas de filtre de date car ce sont des données de base)
            $results['suppliers_count'] = Supplierproduct::where('store_id', $storeId)->count();

            // Requête 4: Statistiques des factures (optimisée en une seule requête) avec filtres de dates
            $invoiceStats = $this->getInvoiceStatistics($storeId, $startDate, $endDate);
            $results = array_merge($results, $invoiceStats);

            // Requête 5: Statistiques des ventes avec filtres de dates
            $results['sales_summary'] = $this->getSalesStatistics($storeId, $startDate, $endDate);

        } catch (\Exception $e) {
            // Log l'erreur mais continue avec les valeurs par défaut
            Log::error('Erreur dans executeParallelQueries: ' . $e->getMessage());
        }

        return $results;
    }

    private function getInvoiceStatistics($storeId, $startDate = null, $endDate = null)
    {
        // Une seule requête pour toutes les statistiques de factures (adapté pour PostgreSQL)
        $query = Invoice::where('store_id', $storeId);

        // Appliquer le filtre de date si fourni
        if ($startDate && $endDate) {
            $query->whereBetween('created_at', [
                Carbon::parse($startDate)->startOfDay(),
                Carbon::parse($endDate)->endOfDay()
            ]);
        }

        $invoices = $query->selectRaw("
                COUNT(*) as total_count,
                SUM(CASE WHEN invoice_status = 'no_paid' THEN 1 ELSE 0 END) as no_paid_count,
                SUM(CASE WHEN invoice_status = 'paid' THEN 1 ELSE 0 END) as paid_count,
                SUM(CASE WHEN invoice_status = 'partial' THEN 1 ELSE 0 END) as partial_count,
                SUM(CASE WHEN invoice_status = 'cancelled' OR is_cancelled = true THEN 1 ELSE 0 END) as cancelled_count
            ")
            ->first();

        return [
            'total_invoices_count' => (int) ($invoices->total_count ?? 0),
            'invoices_no_paid' => (int) ($invoices->no_paid_count ?? 0),
            'invoices_paid' => (int) ($invoices->paid_count ?? 0),
            'invoices_partial' => (int) ($invoices->partial_count ?? 0),
            'invoices_cancelled' => (int) ($invoices->cancelled_count ?? 0),
            'invoices_pending' => 0 // À ajuster si vous avez un statut "pending" pour les factures
        ];
    }


    // Méthodes individuelles pour compatibilité (optionnel)
    public function nbCustomer($storeId)
    {
        return Customer::where('store_id', $storeId)->count();
    }

    public function nbSupplier($storeId)
    {
        return Supplierproduct::where('store_id', $storeId)->count();
    }

    public function nbProduct($storeId)
    {
        return Product::where('store_id', $storeId)->count();
    }

public function getAllStatistics(Request $request)
{
    $context = $this->companyStoreResolver->resolveStoreAndCompany($request);
    $storeId = $context['store_id'];

    if (!$storeId) {
        return null;
    }

    // Récupérer les paramètres de date optionnels
    $startDate = $request->input('start_date');
    $endDate = $request->input('end_date');

    return [
        'customers' => $this->nbCustomer($storeId),
        'suppliers' => $this->nbSupplier($storeId),
        'products' => $this->nbProduct($storeId),
        'invoices' => [
            'total' => $this->nbInvoices($storeId, null, $startDate, $endDate),
            'paid' => $this->nbInvoices($storeId, 'paid', $startDate, $endDate),
            'no_paid' => $this->nbInvoices($storeId, 'no_paid', $startDate, $endDate),
            'partial' => $this->nbInvoices($storeId, 'partial', $startDate, $endDate),
            'cancelled' => $this->nbInvoices($storeId, 'cancel', $startDate, $endDate),
        ],
        'amounts' => $this->getInvoiceAmounts($storeId, $startDate, $endDate),
        'sales' => $this->getSalesStatistics($storeId, $startDate, $endDate),
        'stock_value' => $this->getStockValue($storeId),
        'updated_at' => now()->toDateTimeString()
    ];
}

/**
 * Valeur cumulée du stock actuel de la boutique.
 *
 * Pour chaque produit : base_unit_quantity (stock physique) × prix de l'unité de base.
 * C'est une photo du stock à l'instant présent : pas de filtre de date.
 */
private function getStockValue($storeId): float
{
    $value = Product::where('products.store_id', $storeId)
        ->join('unit_of_measures', function ($join) {
            $join->on('unit_of_measures.product_id', '=', 'products.id')
                 ->where('unit_of_measures.is_base_unit', true)
                 ->whereNull('unit_of_measures.deleted_at');
        })
        ->selectRaw('COALESCE(SUM(products.base_unit_quantity * unit_of_measures.price), 0) as stock_value')
        ->value('stock_value');

    return (float) ($value ?? 0);
}

// Nouvelle méthode pour récupérer les montants des factures
private function getInvoiceAmounts($storeId, $startDate = null, $endDate = null)
{
    $query = Invoice::where('store_id', $storeId);

    // Appliquer le filtre de date si fourni
    if ($startDate && $endDate) {
        $query->whereBetween('created_at', [
            Carbon::parse($startDate)->startOfDay(),
            Carbon::parse($endDate)->endOfDay()
        ]);
    }

    // Exclure les factures annulées des calculs de montants
    $amounts = $query
        ->where(function($q) {
            $q->where('is_cancelled', false)
              ->orWhereNull('is_cancelled');
        })
        ->where(function($q) {
            $q->where('invoice_status', '!=', 'cancelled')
              ->orWhereNull('invoice_status');
        })
        ->selectRaw("
            SUM(amount_total) as total_amount,
            SUM(amount_paid) as total_paid,
            SUM(amount_total - amount_paid) as total_remaining
        ")
        ->first();

    return [
        'total_amount' => (float) ($amounts->total_amount ?? 0),
        'total_paid' => (float) ($amounts->total_paid ?? 0),
        'total_unpaid' => (float) ($amounts->total_remaining ?? 0),
        'payment_rate' => ($amounts->total_amount ?? 0) > 0
            ? round((($amounts->total_paid ?? 0) / ($amounts->total_amount ?? 0)) * 100, 2)
            : 0
    ];
}

// Modifier nbInvoices pour supporter les filtres de date
public function nbInvoices($storeId, $status = null, $startDate = null, $endDate = null)
{
    $query = Invoice::where('store_id', $storeId);

    // Appliquer le filtre de date si fourni
    if ($startDate && $endDate) {
        $query->whereBetween('created_at', [
            Carbon::parse($startDate)->startOfDay(),
            Carbon::parse($endDate)->endOfDay()
        ]);
    }

    if ($status) {
        if ($status === 'cancel') {
            $query->where(function($q) {
                $q->where('invoice_status', 'cancelled')
                  ->orWhere('is_cancelled', true);
            });
        } else {
            $query->where('invoice_status', $status);
        }
    }

    return $query->count();
}

// Modifier getSalesStatistics pour supporter les filtres de date
private function getSalesStatistics($storeId, $startDate = null, $endDate = null)
{
    $query = Sale::where('store_id', $storeId);

    // Appliquer le filtre de date si fourni
    if ($startDate && $endDate) {
        $query->whereBetween('created_at', [
            Carbon::parse($startDate)->startOfDay(),
            Carbon::parse($endDate)->endOfDay()
        ]);
    }

    $sales = $query
        ->selectRaw("
            COUNT(*) as total_count,
            SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_count,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
            SUM(CASE WHEN status = 'confirmed' THEN total_amount ELSE 0 END) as confirmed_amount,
            SUM(CASE WHEN status = 'pending' THEN total_amount ELSE 0 END) as pending_amount
        ")
        ->first();

    return [
        'confirmed' => (int) ($sales->confirmed_count ?? 0),
        'pending' => (int) ($sales->pending_count ?? 0),
        'cancelled' => (int) ($sales->cancelled_count ?? 0),
        'confirmed_amount' => (float) ($sales->confirmed_amount ?? 0),
        'pending_amount' => (float) ($sales->pending_amount ?? 0),
        'total_count' => (int) ($sales->total_count ?? 0)
    ];
}
}
