<?php

namespace App\Http\Controllers;

use App\Services\AdminStatsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Tableau de bord statistique de l'administrateur.
 */
class AdminStatsController extends Controller
{
    public function __construct(protected AdminStatsService $stats)
    {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'start' => 'required|date',
            'end' => 'required|date|after_or_equal:start',
            'company_id' => 'nullable|integer|exists:companies,id',
        ], [
            'start.required' => 'La date de début est obligatoire.',
            'end.required' => 'La date de fin est obligatoire.',
            'end.after_or_equal' => 'La date de fin doit être postérieure ou égale à la date de début.',
        ]);

        $start = CarbonImmutable::parse($validated['start']);
        $end = CarbonImmutable::parse($validated['end']);

        if ($start->diffInDays($end) > 366 * 3) {
            return response()->json([
                'status' => false,
                'message' => 'La période ne peut pas dépasser 3 ans.',
            ], 422);
        }

        return response()->json(
            $this->stats->build($start, $end, isset($validated['company_id']) ? (int) $validated['company_id'] : null)
        );
    }
}
