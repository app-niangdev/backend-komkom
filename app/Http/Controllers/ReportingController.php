<?php

namespace App\Http\Controllers;

use App\Services\ReportingService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ReportingController extends Controller
{
    protected ReportingService $reportingService;

    public function __construct(ReportingService $reportingService)
    {
        $this->reportingService = $reportingService;
    }

    public function getDashboardData(Request $request)
    {
        // 🔹 Récupération des paramètres
        $year = $request->query('year');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        /**
         * PRIORITÉ DES FILTRES
         * Les paramètres de dates sont automatiquement transmis via $request
         * à toutes les méthodes du ReportingService pour un filtrage dynamique
         */
        if ($startDate && $endDate) {

            // 📆 Période complète
            $invoiceStats = $this->reportingService
                ->getYearlyInvoiceSummary($request, null, $startDate, $endDate);

        } elseif ($startDate && !$endDate) {

            // 📅 Une seule journée
            $date = Carbon::parse($startDate)->format('Y-m-d');

            $invoiceStats = $this->reportingService
                ->getYearlyInvoiceSummary($request, null, $date, $date);

        } elseif ($year) {

            // 🗓️ Année spécifique
            $invoiceStats = $this->reportingService
                ->getYearlyInvoiceSummary($request, (int) $year);

        } else {

            // 🟢 Année courante
            $invoiceStats = $this->reportingService
                ->getYearlyInvoiceSummary($request);
        }

        return response()->json([
            'data' => [
                'invoiceStats' => $invoiceStats,
                'getAllStatistics' => $this->reportingService->getAllStatistics($request),
                'fixData' => $this->reportingService->getFixData($request),
            ]
        ]);
    }
}
