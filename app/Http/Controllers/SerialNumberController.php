<?php

namespace App\Http\Controllers;

use App\Models\SerialNumber;
use App\Http\Resources\SerialNumberResource;
use App\Services\CompanyStoreResolverService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class SerialNumberController extends Controller
{
    protected $companyStoreResolver;

    public function __construct(CompanyStoreResolverService $companyStoreResolver)
    {
        $this->companyStoreResolver = $companyStoreResolver;
    }

    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 12);
            $search = $request->input('search');
            $productId = $request->input('product_id');
            $isSold = $request->input('is_sold');
            $onlyAvailable = $request->input('only_available');
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
            $query = SerialNumber::query()
                ->with(['product', 'supplyLineItem.supply.supplier', 'saleLineItem'])
                ->whereHas('product', function ($productQuery) use ($storeId) {
                    $productQuery->where('store_id', $storeId);
                })
                ->withHistory(); // On retourne tout l'historique (reçu)

            // Recherche globale
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('serial_number', 'ILIKE', "%{$search}%")
                      ->orWhereHas('product', function ($productQuery) use ($search) {
                          $productQuery->where('name', 'ILIKE', "%{$search}%")
                                      ->orWhere('description', 'ILIKE', "%{$search}%");
                      });
                });
            }

            // Filtre par produit
            if ($productId) {
                $query->where('product_id', $productId);
            }

            // Filtre par statut de vente
            if ($isSold !== null) {
                $query->where('is_sold', filter_var($isSold, FILTER_VALIDATE_BOOLEAN));
            }

            $query->orderBy('created_at', 'desc');

            $serialNumbers = $query->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'data' => SerialNumberResource::collection($serialNumbers),
                'meta' => [
                    'current_page' => $serialNumbers->currentPage(),
                    'per_page' => $serialNumbers->perPage(),
                    'total' => $serialNumbers->total(),
                    'last_page' => $serialNumbers->lastPage(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur dans SerialNumberController@index : ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la récupération des numéros de série.'
            ], 500);
        }
    }

    /**
     * Store a newly created serial number in storage.
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'product_id' => 'required|exists:products,id',
                'supply_line_item_id' => 'nullable|exists:supply_line_items,id',
                'sale_line_item_id' => 'nullable|exists:sale_line_items,id',
                'serial_number' => 'required|string|unique:serial_numbers,serial_number',
                'is_sold' => 'boolean',
            ]);

            $context = $this->companyStoreResolver->resolveStoreAndCompany($request);
            $storeId = $context['store_id'];

            // Vérifier que le produit appartient bien à la boutique
            $product = \App\Models\Product::where('id', $validated['product_id'])
                ->where('store_id', $storeId)
                ->first();

            if (!$product) {
                return response()->json([
                    'status' => false,
                    'message' => 'Le produit n\'existe pas ou n\'appartient pas à votre boutique.'
                ], 422);
            }

            $serialNumber = SerialNumber::create($validated);
            $product->syncStockFromSerialNumbers();

            return response()->json([
                'status' => true,
                'message' => 'Numéro de série créé avec succès.',
                'data' => new SerialNumberResource($serialNumber->load(['product', 'supplyLineItem', 'saleLineItem']))
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Erreur de validation.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Erreur dans SerialNumberController@store : ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la création du numéro de série.'
            ], 500);
        }
    }

    /**
     * Display the specified serial number.
     */
    public function show(Request $request, $id)
    {
        try {
            $context = $this->companyStoreResolver->resolveStoreAndCompany($request);
            $storeId = $context['store_id'];

            $serialNumber = SerialNumber::with(['product', 'supplyLineItem', 'saleLineItem'])
                ->whereHas('product', function ($query) use ($storeId) {
                    $query->where('store_id', $storeId);
                })
                ->find($id);

            if (!$serialNumber) {
                return response()->json([
                    'status' => false,
                    'message' => 'Numéro de série non trouvé.'
                ], 404);
            }

            return response()->json([
                'status' => true,
                'data' => new SerialNumberResource($serialNumber)
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur dans SerialNumberController@show : ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la récupération du numéro de série.'
            ], 500);
        }
    }

    /**
     * Update the specified serial number in storage.
     */
    public function update(Request $request, $id)
    {
        try {
            $context = $this->companyStoreResolver->resolveStoreAndCompany($request);
            $storeId = $context['store_id'];

            $serialNumber = SerialNumber::with('supplyLineItem.supply')
                ->whereHas('product', function ($query) use ($storeId) {
                    $query->where('store_id', $storeId);
                })->find($id);

            if (!$serialNumber) {
                return response()->json([
                    'status' => false,
                    'message' => 'Numéro de série non trouvé.'
                ], 404);
            }

            // Vérifier que l'approvisionnement est reçu avant de permettre une modification manuelle
            if ($serialNumber->supplyLineItem && $serialNumber->supplyLineItem->supply->status !== 'received') {
                return response()->json([
                    'status' => false,
                    'message' => 'Ce numéro de série appartient à un approvisionnement non validé et ne peut pas être modifié individuellement.'
                ], 422);
            }

            // Ajoutez cette validation personnalisée
            $validated = $request->validate([
                'product_id' => 'sometimes|exists:products,id',
                'supply_line_item_id' => 'nullable|exists:supply_line_items,id',
                'sale_line_item_id' => 'nullable|exists:sale_line_items,id',
                'serial_number' => [
                    'sometimes',
                    'string',
                    function ($attribute, $value, $fail) use ($id, $storeId) {
                        // Chercher un serial_number identique dans la même boutique
                        $exists = SerialNumber::where('serial_number', $value)
                            ->where('id', '!=', $id)
                            ->whereHas('product', function ($query) use ($storeId) {
                                $query->where('store_id', $storeId);
                            })
                            ->exists();

                        if ($exists) {
                            $fail('Ce numéro de série existe déjà dans votre boutique.');
                        }
                    },
                ],
                'is_sold' => 'boolean',
            ]);
            // Si product_id est modifié, vérifier l'appartenance à la boutique
            if (isset($validated['product_id'])) {
                $product = \App\Models\Product::where('id', $validated['product_id'])
                    ->where('store_id', $storeId)
                    ->first();

                if (!$product) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Le produit n\'existe pas ou n\'appartient pas à votre boutique.'
                    ], 422);
                }
            }

            $serialNumber->update($validated);

            return response()->json([
                'status' => true,
                'message' => 'Numéro de série mis à jour avec succès.',
                'data' => new SerialNumberResource($serialNumber->load(['product', 'supplyLineItem.supply.supplier', 'saleLineItem']))
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Erreur de validation.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Erreur dans SerialNumberController@update : ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la mise à jour du numéro de série.'
            ], 500);
        }
    }
    /**
     * Remove the specified serial number from storage.
     */
    public function destroy(Request $request, $id)
    {
        try {
            $context = $this->companyStoreResolver->resolveStoreAndCompany($request);
            $storeId = $context['store_id'];

            $serialNumber = SerialNumber::with('supplyLineItem.supply')
                ->whereHas('product', function ($query) use ($storeId) {
                    $query->where('store_id', $storeId);
                })->find($id);

            if (!$serialNumber) {
                return response()->json([
                    'status' => false,
                    'message' => 'Numéro de série non trouvé.'
                ], 404);
            }

            // Vérifier que l'approvisionnement est reçu avant de permettre une suppression manuelle
            if ($serialNumber->supplyLineItem && $serialNumber->supplyLineItem->supply->status !== 'received') {
                return response()->json([
                    'status' => false,
                    'message' => 'Ce numéro de série appartient à un approvisionnement non validé et ne peut pas être supprimé individuellement.'
                ], 422);
            }

            $product = $serialNumber->product;
            $serialNumber->delete();

            if ($product) {
                $product->syncStockFromSerialNumbers();
            }

            return response()->json([
                'status' => true,
                'message' => 'Numéro de série supprimé avec succès.'
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur dans SerialNumberController@destroy : ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la suppression du numéro de série.'
            ], 500);
        }
    }

    /**
     * Get available serial numbers for a product (not sold).
     */
    public function getAvailableForProduct(Request $request, $productId)
    {
        try {
            $context = $this->companyStoreResolver->resolveStoreAndCompany($request);
            $storeId = $context['store_id'];

            $product = \App\Models\Product::where('id', $productId)
                ->where('store_id', $storeId)
                ->first();

            if (!$product) {
                return response()->json([
                    'status' => false,
                    'message' => 'Produit non trouvé.'
                ], 404);
            }

            $serialNumbers = SerialNumber::where('product_id', $productId)
                ->available()
                ->orderBy('serial_number')
                ->get();

            return response()->json([
                'status' => true,
                'data' => SerialNumberResource::collection($serialNumbers)
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur dans SerialNumberController@getAvailableForProduct : ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la récupération des numéros de série disponibles.'
            ], 500);
        }
    }
}
