<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerRequest;
use App\Models\Customer;
use App\Services\CompanyStoreResolverService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CustomerController extends Controller
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

            Log::info("------------");
            Log::info($storeId);

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Impossible de déterminer la boutique associée à cet utilisateur.'
                ], 422);
            }

            // Création de la requête avec filtre par ID
            $query = Customer::query()
                ->where('store_id', $storeId);


            // Recherche globale
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'ILIKE', "%{$search}%")
                        ->orWhere('phone', 'ILIKE', "%{$search}%")
                        ->orWhere('address', 'ILIKE', "%{$search}%")
                        ->orWhere('email', 'ILIKE', "%{$search}%");
                });
            }

            $query->orderBy('created_at', 'desc');

            $customers = $query->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'data' => $customers->items(),
                'meta' => [
                    'current_page' => $customers->currentPage(),
                    'per_page' => $customers->perPage(),
                    'total' => $customers->total(),
                    'last_page' => $customers->lastPage(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur : ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la récupération des clients.'
            ], 500);
        }
    }


    /**
     * Création d’un fournisseur produit
     */
    public function store(CustomerRequest $request)
    {
        $validatedData = $request->validated();

        Customer::create($validatedData);

        return response()->json([
            'status' => true,
            'message' => 'Client créé avec succès.',
        ], 201);
    }

    /**
     * Affichage d’un fournisseur produit spécifique
     */
    public function show($id)
    {
        $supplier = Customer::find($id);

        if (!$supplier) {
            return response()->json([
                'status' => false,
                'message' => 'Client non trouvé.',
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
    public function update(CustomerRequest $request, $id)
    {
        $customer = Customer::find($id);

        if (!$customer) {
            return response()->json([
                'status' => false,
                'message' => 'Fournisseur non trouvé.',
            ], 404);
        }

        $customer->update($request->validated());

        return response()->json([
            'status' => true,
            'message' => 'Client mis à jour avec succès.',
        ], 200);
    }

    /**
     * Suppression d'un fournisseur produit
     */
    public function destroy($id)
    {
        $customer = Customer::find($id);

        if (!$customer) {
            return response()->json([
                'status' => false,
                'message' => "Ce client n'existe pas.",
            ], 404);
        }

        // Vérifier si le client est lié à des ventes
        if (\App\Models\Sale::where('customer_id', $id)->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Impossible de supprimer ce client car il est lié à une ou plusieurs ventes.'
            ], 422);
        }

        // Vérifier si le client est lié à des factures via sale
        if (\App\Models\Invoice::whereHas('sale', function ($query) use ($id) {
            $query->where('customer_id', $id);
        })->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Impossible de supprimer ce client car il est lié à une ou plusieurs factures.'
            ], 422);
        }

        $customer->delete();

        return response()->json([
            'status' => true,
            'message' => 'client supprimé avec succès.',
        ], 200);
    }
}
