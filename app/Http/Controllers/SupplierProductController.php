<?php

namespace App\Http\Controllers;

use App\Models\Supplierproduct;
use App\Http\Requests\SupplierRequest;
use App\Services\CompanyStoreResolverService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SupplierProductController extends Controller
{

    protected $companyStoreResolver;

    public function __construct(CompanyStoreResolverService $companyStoreResolver)
    {
        $this->companyStoreResolver = $companyStoreResolver;
    }
    /**
     * Liste paginée des fournisseurs produits
     */
    public function index(Request $request)
    {
        try {

            $perPage = $request->input('per_page', 10);
            $search = $request->input('search');
            $page = (int) $request->input('page', 1);

            $context = $this->companyStoreResolver->resolveStoreAndCompany($request);
            $storeId = $context['store_id'];

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Impossible de déterminer la boutique associée à cet utilisateur.'
                ], 422);
            }

            // Création de la requête avec filtre par ID
            $query = Supplierproduct::query()
                ->where('store_id', $storeId); // 👈 ici tu filtres par ton identifiant


            // Recherche globale
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'ILIKE', "%{$search}%")
                        ->orWhere('phone_one', 'ILIKE', "%{$search}%")
                        ->orWhere('address', 'ILIKE', "%{$search}%")
                        ->orWhere('email', 'ILIKE', "%{$search}%");
                });
            }

            $query->orderBy('created_at', 'desc');

            $suppliers = $query->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'data' => $suppliers->items(),
                'meta' => [
                    'current_page' => $suppliers->currentPage(),
                    'per_page' => $suppliers->perPage(),
                    'total' => $suppliers->total(),
                    'last_page' => $suppliers->lastPage(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur dans allCategories : ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la récupération des catégories.'
            ], 500);
        }
    }


    /**
     * Création d’un fournisseur produit
     */
    public function store(SupplierRequest $request)
    {
        $validatedData = $request->validated();

        Supplierproduct::create($validatedData);

        return response()->json([
            'status' => true,
            'message' => 'Fournisseur créé avec succès.',
        ], 201);
    }

    /**
     * Affichage d’un fournisseur produit spécifique
     */
    public function show($id)
    {
        $supplier = Supplierproduct::find($id);

        if (!$supplier) {
            return response()->json([
                'status' => false,
                'message' => 'Fournisseur non trouvé.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $supplier,
        ], 200);
    }

    /**
     * Mise à jour d’un fournisseur produit
     */
    public function update(SupplierRequest $request, $id)
    {
        $supplier = Supplierproduct::find($id);

        if (!$supplier) {
            return response()->json([
                'status' => false,
                'message' => 'Fournisseur non trouvé.',
            ], 404);
        }

        $supplier->update($request->validated());

        return response()->json([
            'status' => true,
            'message' => 'Fournisseur mis à jour avec succès.',
        ], 200);
    }

    /**
     * Suppression d'un fournisseur produit
     */
    public function destroy($id)
    {
        $supplier = Supplierproduct::find($id);

        if (!$supplier) {
            return response()->json([
                'status' => false,
                'message' => "Ce fournisseur n'existe pas.",
            ], 404);
        }

        // Vérifier si le fournisseur est lié à des approvisionnements
        if (\App\Models\Supply::where('supplier_id', $id)->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Impossible de supprimer ce fournisseur car il est lié à un ou plusieurs approvisionnements.',
            ], 422);
        }

        $supplier->delete();

        return response()->json([
            'status' => true,
            'message' => 'Fournisseur supprimé avec succès.',
        ], 200);
    }
}
