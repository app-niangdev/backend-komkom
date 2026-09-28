<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductStoreRequest;
use App\Http\Requests\ProductUpdateRequest;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ProductAvailableResource;
use App\Services\CompanyStoreResolverService;
use App\Services\FileValidationService;

class ProductController extends Controller
{
    protected $companyStoreResolver;

    public function __construct(CompanyStoreResolverService $companyStoreResolver)
    {
        $this->companyStoreResolver = $companyStoreResolver;
    }

    /**
     * Liste paginée des produits
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 12);
            $search = $request->input('search');
            $categoryId = $request->input('category_id');
            $page = (int) $request->input('page', 1);

            $context = $this->companyStoreResolver->resolveStoreAndCompany($request);
            $storeId = $context['store_id'];

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Impossible de déterminer la boutique associée à cet utilisateur.'
                ], 422);
            }

            // Création de la requête avec filtre par boutique
            $query = Product::query()
                ->with(['category', 'unitOfMeasures'])
                ->where('store_id', $storeId);

            // Recherche globale
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'ILIKE', "%{$search}%")
                      ->orWhere('description', 'ILIKE', "%{$search}%")
                      ->orWhereHas('category', function ($categoryQuery) use ($search) {
                          $categoryQuery->where('name', 'ILIKE', "%{$search}%");
                      });
                });
            }

            // Filtre par catégorie
            if ($categoryId) {
                $query->where('category_id', $categoryId);
            }

            $query->orderBy('created_at', 'desc');

            $products = $query->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'data' => ProductResource::collection($products),
                'meta' => [
                    'current_page' => $products->currentPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                    'last_page' => $products->lastPage(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur dans ProductController@index : ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la récupération des produits.'
            ], 500);
        }
    }

    /**
     * Création d'un nouveau produit
     */
    public function store(ProductStoreRequest $request, FileValidationService $fileValidator)
    {
        try {
            DB::beginTransaction();

            $context = $this->companyStoreResolver->resolveStoreAndCompany($request);
            $storeId = $context['store_id'];

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Impossible de déterminer la boutique associée à cet utilisateur.'
                ], 422);
            }

            $validated = $request->validated();
            $store = Store::find($storeId);
            $usesMeasurements = $store?->uses_measurements ?? true;
            $unitPrice = $validated['unit_price'] ?? 0;

            if ($usesMeasurements) {
                $baseUnits = array_filter($validated['unit_of_measures'] ?? [], static function ($uom) {
                    return $uom['is_base_unit'] ?? false;
                });

                if (count($baseUnits) !== 1) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Il doit y avoir exactement une unité de mesure définie comme unité de base.'
                    ], 422);
                }
            }

            // Créer le produit
            $product = Product::create([
                'store_id' => $storeId,
                'category_id' => $validated['category_id'] ?? null,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'require_serial_number' => $validated['require_serial_number'] ?? false,
                'alert_threshold' => $validated['alert_threshold'] ?? null,
                'base_unit' => $usesMeasurements
                    ? $validated['base_unit']
                    : Product::DEFAULT_UNIT_WITHOUT_MEASUREMENTS,
                'base_unit_quantity' => 0,
            ]);

            if ($request->hasFile('image')) {
                $file = $request->file('image');
                $fileValidator->validateAndStoreFile($file, $product, 'image');
            } elseif (!empty($validated['image_url'])) {
                $fileValidator->validateAndStoreFileFromUrl($validated['image_url'], $product, 'image');
            }

            if ($usesMeasurements) {
                foreach ($validated['unit_of_measures'] as $uomData) {
                    $product->unitOfMeasures()->create($uomData);
                }
            } else {
                $product->unitOfMeasures()->create([
                    'name' => Product::DEFAULT_UNIT_WITHOUT_MEASUREMENTS,
                    'price' => $unitPrice,
                    'conversion_factor' => 1,
                    'is_base_unit' => true,
                ]);
            }

            // Charger les relations pour la réponse
            $product->load(['category', 'unitOfMeasures']);


            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Produit créé avec succès.',
                // 'data' => new ProductResource($product)
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur dans ProductController@store : ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la création du produit.'
            ], 500);
        }
    }

    /**
     * Mise à jour d'un produit
     */
    public function update(ProductUpdateRequest $request, $id, FileValidationService $fileValidator)
    {
        try {
            DB::beginTransaction();

            Log::info([
                'all' => $request->all(),
                'files' => $request->files->keys(),
                'hasFileImage' => $request->hasFile('image'),
            ]);


            $context = $this->companyStoreResolver->resolveStoreAndCompany($request);
            $storeId = $context['store_id'];

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Impossible de déterminer la boutique associée à cet utilisateur.'
                ], 422);
            }

            $product = Product::with('unitOfMeasures')->where('store_id', $storeId)->find($id);

            if (!$product) {
                return response()->json([
                    'status' => false,
                    'message' => 'Produit introuvable pour cette boutique.'
                ], 404);
            }

            $validated = $request->validated();
            $store = Store::find($storeId);
            $usesMeasurements = $store?->uses_measurements ?? true;
            $unitPrice = $validated['unit_price'] ?? 0;

            if ($usesMeasurements) {
                $baseUnits = array_filter($validated['unit_of_measures'] ?? [], static fn($uom) => $uom['is_base_unit'] ?? false);
                if (count($baseUnits) !== 1) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Il doit y avoir exactement une unité de mesure définie comme unité de base.'
                    ], 422);
                }
            }

            // ✅ Mise à jour du produit
            $product->update([
                'category_id' => $validated['category_id'] ?? null,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'require_serial_number' => $validated['require_serial_number'] ?? false,
                'alert_threshold' => $validated['alert_threshold'] ?? null,
                'base_unit' => $usesMeasurements
                    ? $validated['base_unit']
                    : Product::DEFAULT_UNIT_WITHOUT_MEASUREMENTS,
            ]);

            // ✅ Gestion de l'image (si nouvelle image ou nouveau lien)
            if ($request->hasFile('image')) {
                $file = $request->file('image');
                $fileValidator->validateAndStoreFile($file, $product, 'image');
            } elseif (!empty($validated['image_url'])) {
                $fileValidator->validateAndStoreFileFromUrl($validated['image_url'], $product, 'image');
            }

            if ($usesMeasurements) {
                // ✅ Synchronisation des unités de mesure
                $existingIds = $product->unitOfMeasures->pluck('id')->toArray();
                $incomingIds = collect($validated['unit_of_measures'])->pluck('id')->filter()->toArray();

                // Supprimer les unités supprimées
                $toDelete = array_diff($existingIds, $incomingIds);
                if (!empty($toDelete)) {
                    $product->unitOfMeasures()->whereIn('id', $toDelete)->delete();
                }

                // Mettre à jour ou créer les unités
                foreach ($validated['unit_of_measures'] as $uomData) {
                    if (isset($uomData['id'])) {
                        // Mise à jour
                        $uom = $product->unitOfMeasures()->where('id', $uomData['id'])->first();
                        if ($uom) {
                            $uom->update($uomData);
                        }
                    } else {
                        // Nouvelle unité
                        $product->unitOfMeasures()->create($uomData);
                    }
                }
            } else {
                $product->unitOfMeasures()->delete();
                $product->unitOfMeasures()->create([
                    'name' => Product::DEFAULT_UNIT_WITHOUT_MEASUREMENTS,
                    'price' => $unitPrice,
                    'conversion_factor' => 1,
                    'is_base_unit' => true,
                ]);
            }

            $product->load(['category', 'unitOfMeasures']);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Produit mis à jour avec succès.',
                // 'data' => new ProductResource($product)
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur dans ProductController@update : ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la mise à jour du produit.'
            ], 500);
        }
    }

    /**
     * Suppression d'un produit
     */
    public function delete($id)
    {
        try {
            DB::beginTransaction();

            // Note: On ne passe pas la requête ici, donc on ne peut pas résoudre la boutique
            // Vous devrez adapter cette logique selon votre architecture
            $product = Product::findOrFail($id);

            // Vérifier si le produit a des mouvements de stock
            if ($product->base_unit_quantity > 0) {
                return response()->json([
                    'status' => false,
                    'message' => 'Impossible de supprimer le produit car il reste du stock.'
                ], 422);
            }

            // Vérifier si le produit est lié à des ventes
            if (\App\Models\SaleLineItem::where('product_id', $id)->exists()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Impossible de supprimer ce produit car il est lié à une ou plusieurs ventes.'
                ], 422);
            }

            // Vérifier si le produit est lié à des approvisionnements
            if (\App\Models\SupplyLineItem::where('product_id', $id)->exists()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Impossible de supprimer ce produit car il est lié à un ou plusieurs approvisionnements.'
                ], 422);
            }

            // Vérifier si le produit a des numéros de série
            if (\App\Models\SerialNumber::where('product_id', $id)->exists()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Impossible de supprimer ce produit car il possède des numéros de série enregistrés.'
                ], 422);
            }

            $product->delete();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Produit supprimé avec succès.'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Produit non trouvé.'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur dans ProductController@delete : ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la suppression du produit.'
            ], 500);
        }
    }

    /**
     * Récupère les produits disponibles avec leur stock
     */
    public function getProductAvailable(Request $request)
    {
        try {
            $search = $request->input('search');
            $categoryId = $request->input('category_id');
            $perPage = $request->input('per_page', 20);
            $page = (int) $request->input('page', 1);

            $context = $this->companyStoreResolver->resolveStoreAndCompany($request);
            $storeId = $context['store_id'];

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Impossible de déterminer la boutique associée à cet utilisateur.'
                ], 422);
            }

            $query = Product::query()
                ->with(['category', 'unitOfMeasures', 'serialNumbers' => function ($query) {
                    $query->available();
                }])
                ->where('store_id', $storeId);

            $query->where(function ($q) {
                $q->where(function ($serialProducts) {
                    $serialProducts->where('require_serial_number', true)
                        ->whereHas('serialNumbers', function ($sn) {
                            $sn->available();
                        });
                })->orWhere(function ($standardProducts) {
                    $standardProducts->where('require_serial_number', false)
                        ->where('base_unit_quantity', '>=', 1);
                });
            });


            // Recherche globale
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'ILIKE', "%{$search}%")
                      ->orWhereHas('category', function ($categoryQuery) use ($search) {
                          $categoryQuery->where('name', 'ILIKE', "%{$search}%");
                      })
                      ->orWhereHas('unitOfMeasures', function ($uomQuery) use ($search) {
                          $uomQuery->where('name', 'ILIKE', "%{$search}%");
                      });
                });
            }

            // Filtre par catégorie
            if ($categoryId) {
                $query->where('category_id', $categoryId);
            }

            $products = $query->orderBy('name')->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'status' => true,
                'data' => ProductAvailableResource::collection($products),
                'meta' => [
                    'current_page' => $products->currentPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                    'last_page' => $products->lastPage(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur dans ProductController@getProductAvailable : ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la récupération des produits disponibles.'
            ], 500);
        }
    }

    /**
     * Récupère les produits disponibles avec leur stock pour une boutique donnée.
     * Route publique : ne nécessite pas d'authentification.
     */
    public function getProductAvailableByStore(Request $request, int $storeId)
    {
        try {
            $store = Store::find($storeId);

            if (!$store) {
                return response()->json([
                    'status' => false,
                    'message' => 'Boutique introuvable.'
                ], 404);
            }

            $search = $request->input('search');
            $categoryId = $request->input('category_id');
            $perPage = $request->input('per_page', 10);
            $page = (int) $request->input('page', 1);

            $query = Product::query()
                ->with(['category', 'unitOfMeasures', 'serialNumbers' => function ($query) {
                    $query->available();
                }])
                ->where('store_id', $storeId);

            $query->where(function ($q) {
                $q->where(function ($serialProducts) {
                    $serialProducts->where('require_serial_number', true)
                        ->whereHas('serialNumbers', function ($sn) {
                            $sn->available();
                        });
                })->orWhere(function ($standardProducts) {
                    $standardProducts->where('require_serial_number', false)
                        ->where('base_unit_quantity', '>=', 1);
                });
            });

            // Recherche globale
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'ILIKE', "%{$search}%")
                      ->orWhereHas('category', function ($categoryQuery) use ($search) {
                          $categoryQuery->where('name', 'ILIKE', "%{$search}%");
                      })
                      ->orWhereHas('unitOfMeasures', function ($uomQuery) use ($search) {
                          $uomQuery->where('name', 'ILIKE', "%{$search}%");
                      });
                });
            }

            // Filtre par catégorie
            if ($categoryId) {
                $query->where('category_id', $categoryId);
            }

            $products = $query->orderBy('name')->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'status' => true,
                'data' => ProductAvailableResource::collection($products),
                'meta' => [
                    'current_page' => $products->currentPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                    'last_page' => $products->lastPage(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur dans ProductController@getProductAvailableByStore : ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la récupération des produits disponibles.'
            ], 500);
        }
    }
}
