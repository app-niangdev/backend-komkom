<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Services\CompanyStoreResolverService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class CategoryController extends Controller
{

    protected $companyStoreResolver;

    public function __construct(CompanyStoreResolverService $companyStoreResolver)
    {
        $this->companyStoreResolver = $companyStoreResolver;
    }

    public function allCategories(Request $request)
    {
        try {
            $user = Auth::user();
            $storeId = null;

            // 🧠 Cas 1 : Si c’est un vendeur, on déduit directement la boutique
            if ($user->role->name === 'Seller') {
                $storeId = $user->store_id; // le vendeur est lié à une seule boutique
            }
            // 🧠 Cas 2 : Si c’est un Owner ou un Manager, il doit préciser la boutique
            else {
                $storeId = $request->input('store_id');
            }

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Le paramètre boutique est obligatoire.'
                ], 422);
            }

            $categories = Category::where('store_id', $storeId)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur dans allCategories : ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la récupération des catégories.'
            ], 500);
        }
    }


    public function index(Request $request)
    {
        try {
            // 🔹 Récupération du store selon le rôle (Seller / Manager / Owner)
            $context = $this->companyStoreResolver->resolveStoreAndCompany($request);
            $storeId = $context['store_id'];

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Impossible de déterminer la boutique associée à cet utilisateur.'
                ], 422);
            }

            // 🔹 Paramètres de pagination & recherche
            $perPage = (int) $request->input('perPage', 10);
            $page = (int) $request->input('page', 1);
            $search = trim($request->input('search', ''));

            // 🔹 Construction de la requête
            $query = Category::where('store_id', $storeId);

            if ($search !== '') {
                $query->where(function (Builder $builder) use ($search) {
                    $builder->where('name', 'ILIKE', "%{$search}%")
                            ->orWhere('description', 'ILIKE', "%{$search}%");
                });
            }

            $categories = $query->orderByDesc('created_at')
                                ->paginate($perPage, ['*'], 'page', $page);

            // 🔹 Réponse finale
            return response()->json([
                'status' => true,
                'data' => $categories->items(),
                'meta' => [
                    'current_page' => $categories->currentPage(),
                    'per_page' => $categories->perPage(),
                    'total' => $categories->total(),
                    'last_page' => $categories->lastPage(),
                ]
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Erreur dans CategoryController@index : ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la récupération des catégories.'
            ], 500);
        }
    }

    /**
     * Création d’une nouvelle catégorie
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:50',
            'description' => 'nullable|string|max:255', // <--- nullable
            'store_id'    => 'required|integer|exists:stores,id',
        ], [
            'name.required'        => 'Le nom de la catégorie est obligatoire.',
            'name.string'          => 'Le nom doit être une chaîne de caractères.',
            'name.max'             => 'Le nom ne peut pas dépasser 50 caractères.',
            'description.string'   => 'La description doit être une chaîne de caractères.',
            'description.max'      => 'La description ne peut pas dépasser 255 caractères.',
            'store_id.required'    => 'Le magasin (store) est obligatoire.',
            'store_id.integer'     => 'L’identifiant du magasin doit être un entier.',
            'store_id.exists'      => 'Le magasin sélectionné est invalide.',
        ]);

        try {
            Category::create($validated);

            return response()->json([
                'message' => 'Catégorie créée avec succès.',
                'status'  => true
            ], 201);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la création de la catégorie : ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la création de la catégorie.'
            ], 500);
        }
    }

    /**
     * Mise à jour d’une catégorie existante
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:50',
            'description' => 'nullable|string|max:255', // <--- nullable
            'store_id'    => 'required|integer|exists:stores,id',
        ], [
            'name.required'        => 'Le nom de la catégorie est obligatoire.',
            'name.string'          => 'Le nom doit être une chaîne de caractères.',
            'name.max'             => 'Le nom ne peut pas dépasser 50 caractères.',
            'description.string'   => 'La description doit être une chaîne de caractères.',
            'description.max'      => 'La description ne peut pas dépasser 255 caractères.',
            'store_id.required'    => 'Le magasin (store) est obligatoire.',
            'store_id.integer'     => 'L’identifiant du magasin doit être un entier.',
            'store_id.exists'      => 'Le magasin sélectionné est invalide.',
        ]);

        $category = Category::where('id', $id)
                            ->where('store_id', $validated['store_id'])
                            ->first();

        if (!$category) {
            return response()->json([
                'status' => false,
                'message' => 'Catégorie non trouvée pour ce magasin.'
            ], 404);
        }

        try {
            $category->update($validated);

            return response()->json([
                'message' => 'Catégorie mise à jour avec succès.',
                'status'  => true
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la mise à jour de la catégorie : ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la mise à jour.'
            ], 500);
        }
    }

    /**
     * Suppression d’une catégorie (vérifie le store_id)
     */
    public function delete($id)
    {
        $category = Category::where('id', $id)
                            ->first();

        if (!$category) {
            return response()->json([
                'status' => false,
                'message' => 'Catégorie non trouvée pour ce magasin.'
            ], 404);
        }

        if (Product::where('category_id', $id)->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Impossible de supprimer cette catégorie car elle est liée à un ou plusieurs produits.'
            ], 422);
        }

        try {
            $category->delete();

            return response()->json([
                'message' => 'Catégorie supprimée avec succès.',
                'status'  => true
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la suppression de la catégorie : ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la suppression.'
            ], 500);
        }
    }
}
