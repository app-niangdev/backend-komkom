<?php

namespace App\Http\Controllers;
use App\Http\Controllers\Controller;
use App\Http\Resources\SupplyResource;
use App\Models\Supply;
use App\Models\SupplyLineItem;
use App\Models\Product;
use App\Models\SerialNumber;
use App\Models\Store;
use App\Services\CompanyStoreResolverService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SupplyController extends Controller
{
    protected $companyStoreResolver;

    public function __construct(CompanyStoreResolverService $companyStoreResolver)
    {
        $this->companyStoreResolver = $companyStoreResolver;
    }
    /**
     * Liste paginée des approvisionnements avec recherche et filtres.
     */

    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            $search = $request->input('search');
            $status = $request->input('status');
            $startDate = $request->input('start_date'); // Récupération de la date de début
            $endDate = $request->input('end_date');     // Récupération de la date de fin
            $page = (int) $request->input('page', 1);

            $context = $this->companyStoreResolver->resolveStoreAndCompany($request);
            $storeId = $context['store_id'];

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Impossible de déterminer la boutique associée à cet utilisateur.'
                ], 422);
            }

            $query = Supply::query()
                ->with(['supplier', 'user', 'store'])
                ->where('store_id', $storeId);

            // Filtre par plage de dates sur created_at
            if ($startDate && $endDate) {
                // Si les deux dates sont fournies
                $query->whereBetween('created_at', [$startDate, $endDate . ' 23:59:59']);
            } elseif ($startDate) {
                // Si seulement la date de début est fournie
                $query->whereDate('created_at', '>=', $startDate);
            } elseif ($endDate) {
                // Si seulement la date de fin est fournie
                $query->whereDate('created_at', '<=', $endDate);
            }

            // Recherche globale
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('order_number', 'ILIKE', "%{$search}%")
                    ->orWhereHas('supplier', function ($s) use ($search) {
                        $s->where('name', 'ILIKE', "%{$search}%");
                    });
                });
            }

            // Filtre par statut
            if ($status) {
                $query->where('status', $status);
            }

            $query->orderBy('created_at', 'desc');

            $supplies = $query->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'data' => SupplyResource::collection($supplies),
                'meta' => [
                    'current_page' => $supplies->currentPage(),
                    'per_page' => $supplies->perPage(),
                    'total' => $supplies->total(),
                    'last_page' => $supplies->lastPage(),
                    'filters' => [
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'status' => $status,
                        'search' => $search,
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur dans SupplyController: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la récupération des approvisionnements.'
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $context = $this->companyStoreResolver->resolveStoreAndCompany($request);
        $storeId = $context['store_id'];
        $store = Store::find($storeId);
        $usesMeasurements = $store?->uses_measurements ?? true;

        $messages = [
            'supplier_id.required' => 'Le fournisseur est obligatoire.',
            'line_items.required' => 'Les lignes d’approvisionnement sont obligatoires.',
            'line_items.array' => 'Les lignes d’approvisionnement doivent être un tableau.',
            'line_items.*.product_id.required' => 'Le produit est obligatoire pour chaque ligne.',
            'line_items.*.quantity.required' => 'La quantité est obligatoire pour chaque ligne.',
            'line_items.*.quantity.numeric' => 'La quantité doit être un nombre.',
            'line_items.*.purchase_price.required' => 'Le prix d’achat est obligatoire pour chaque ligne.',
            'line_items.*.purchase_price.numeric' => 'Le prix d’achat doit être un nombre.'
        ];

        $validated = $request->validate([
            'supplier_id' => 'required|exists:supplierproducts,id',
            'status' => 'nullable|string|in:pending,received,cancelled',
            'line_items' => 'required|array|min:1',
            'line_items.*.product_id' => 'required|exists:products,id',
            'line_items.*.quantity' => 'required|numeric|min:0.01',
            'line_items.*.purchase_price' => 'required|numeric|min:0',
            'line_items.*.serial_numbers' => 'nullable|array',
            // 'line_items.*.serial_numbers.*' => 'string|distinct'
        ], $messages);

        if (!$usesMeasurements) {
            foreach ($validated['line_items'] as $item) {
                if ((float) $item['quantity'] !== (float) (int) $item['quantity']) {
                    return response()->json([
                        'status' => false,
                        'message' => 'La quantité doit être un nombre entier pour une boutique sans gestion de mesures.'
                    ], 422);
                }
            }
        }

        DB::beginTransaction();

        try {
            $userId = Auth::id();

            // Création de l'approvisionnement
            $supply = Supply::create([
                'store_id' => $storeId,
                'supplier_id' => $validated['supplier_id'],
                'user_id' => $userId,
                'status' => $validated['status'] ?? 'pending',
                'total_amount' => 0,
            ]);

            $totalAmount = 0;

            foreach ($validated['line_items'] as $item) {
                $product = Product::where('id', $item['product_id'])
                    ->where('store_id', $storeId)
                    ->firstOrFail();

                // ⚠️ Vérification et gestion des numéros de série
                $serialCheck = $this->handleSerialNumbers($product, $item);

                if ($serialCheck instanceof \Illuminate\Http\JsonResponse) {
                    DB::rollBack();
                    return $serialCheck; // ← renvoie la réponse JSON d’erreur immédiatement
                }

                // Création de la ligne d'approvisionnement
                $line = new SupplyLineItem([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'base_unit_quantity' => $item['quantity'],
                    'purchase_price' => $item['purchase_price'],
                ]);
                $supply->supplyLineItems()->save($line);

                // Si le produit a des numéros de série, on les relie à la ligne
                // mais on ne les active pas tant que l'approvisionnement n'est pas validé
                if (!empty($item['serial_numbers']) && $product->require_serial_number) {
                    foreach ($item['serial_numbers'] as $sn) {
                        SerialNumber::create([
                            'product_id' => $product->id,
                            'supply_line_item_id' => $line->id,
                            'serial_number' => $sn,
                            'is_sold' => false,
                        ]);
                    }
                }

                $totalAmount += $item['quantity'] * $item['purchase_price'];
            }

            // Mise à jour du montant total
            $supply->update(['total_amount' => $totalAmount]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Approvisionnement créé avec succès.',
            ], 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur dans SupplyController@store : ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la création de l’approvisionnement.'
            ], 500);
        }
    }


    /**
     * Valide un approvisionnement en attente et met à jour les stocks.
     */
    public function validateSupply(Request $request, $id)
    {
        $context = $this->companyStoreResolver->resolveStoreAndCompany($request);
        $storeId = $context['store_id'];

        DB::beginTransaction();

        try {
            // Récupération de l'approvisionnement
            $supply = Supply::with('supplyLineItems.product')
                ->where('id', $id)
                ->where('store_id', $storeId)
                ->firstOrFail();

            // Vérifier que l'approvisionnement est en attente
            if ($supply->status !== 'pending') {
                return response()->json([
                    'status' => false,
                    'message' => 'Seuls les approvisionnements en attente peuvent être validés.'
                ], 422);
            }

            // On met à jour le statut AVANT de synchroniser les stocks des produits à numéros de série.
            // La scope InStock de SerialNumber vérifie que le statut de l'approvisionnement est 'received'.
            $supply->update(['status' => 'received', 'received_at' => now(), 'received_by' => Auth::id()]);

            // Parcourir toutes les lignes d'approvisionnement
            foreach ($supply->supplyLineItems as $line) {
                $product = $line->product;

                if ($product->require_serial_number) {
                    $product->syncStockFromSerialNumbers();
                } else {
                    $product->increment('base_unit_quantity', $line->quantity);
                }

                // Enregistrement du mouvement dans la table stocks
                \App\Models\Stock::create([
                    'store_id' => $storeId,
                    'product_id' => $line->product_id,
                    'quantity' => $line->quantity,
                    'movement_type' => 'in',
                    'reason' => 'Validation approvisionnement #' . $supply->order_number,
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Approvisionnement validé avec succès. Les stocks ont été mis à jour.',
                'data' => new SupplyResource($supply)
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::info($e);
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Approvisionnement introuvable.',
                'errors' => $e
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur dans SupplyController@validateSupply : ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la validation de l\'approvisionnement.'
            ], 500);
        }
    }

    /**
     * Annule un approvisionnement en attente.
     */
    public function cancelSupply(Request $request, $id)
    {
        $context = $this->companyStoreResolver->resolveStoreAndCompany($request);
        $storeId = $context['store_id'];

        DB::beginTransaction();

        try {
            // Récupération de l'approvisionnement
            $supply = Supply::with('supplyLineItems')
                ->where('id', $id)
                ->where('store_id', $storeId)
                ->firstOrFail();

            // Vérifier que l'approvisionnement est en attente
            if ($supply->status !== 'pending') {
                return response()->json([
                    'status' => false,
                    'message' => 'Seuls les approvisionnements en attente peuvent être annulés.'
                ], 422);
            }

            // Suppression définitive : SoftDeletes laisserait les S/N en base et bloquerait la contrainte unique
            $lineIds = $supply->supplyLineItems->pluck('id');
            SerialNumber::withTrashed()
                ->whereIn('supply_line_item_id', $lineIds)
                ->forceDelete();

            // Mise à jour du statut de l'approvisionnement
            $supply->update(['status' => 'cancelled']);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Approvisionnement annulé avec succès.',
                'data' => new SupplyResource($supply)
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Approvisionnement introuvable.'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur dans SupplyController@cancelSupply : ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de l\'annulation de l\'approvisionnement.'
            ], 500);
        }
    }

    /**
     * Vérifie et prépare les numéros de série d'un produit à approvisionner.
     * Exclut les serial numbers appartenant à l'approvisionnement en cours de modification.
     */
    private function handleSerialNumbers(Product $product, array $item, ?int $excludeSupplyId = null): bool|\Illuminate\Http\JsonResponse
    {
        if (!$product->require_serial_number) {
            return true;
        }

        // ── 1. Présence obligatoire ───────────────────────────────────────────────
        if (empty($item['serial_numbers'])) {
            return response()->json([
                'status'  => false,
                'message' => "Le produit '{$product->name}' exige des numéros de série.",
            ], 422);
        }

        $serials = $item['serial_numbers'];

        // ── 2. Correspondance quantité / nombre de S/N ────────────────────────────
        if (count($serials) !== (int) $item['quantity']) {
            return response()->json([
                'status'  => false,
                'message' => "Le produit '{$product->name}' doit avoir exactement {$item['quantity']} numéros de série.",
            ], 422);
        }

        // ── 3. Doublons internes au tableau ───────────────────────────────────────
        $unique     = array_unique($serials);
        $duplicates = array_diff_key($serials, $unique);

        if (!empty($duplicates)) {
            return response()->json([
                'status'  => false,
                'message' => "Le produit '{$product->name}' contient des numéros de série en double : "
                    . implode(', ', array_values($duplicates)),
            ], 422);
        }

        // ── 4. Doublons en base (y compris soft-deleted : la contrainte unique PostgreSQL les inclut)
        $query = SerialNumber::withTrashed()
            ->where('product_id', $product->id)
            ->whereIn('serial_number', $serials);

        if ($excludeSupplyId) {
            $query->whereDoesntHave('supplyLineItem', function ($q) use ($excludeSupplyId) {
                $q->withTrashed()->where('supply_id', $excludeSupplyId);
            });
        }

        $existing = $query->pluck('serial_number')->toArray();

        if (!empty($existing)) {
            return response()->json([
                'status'  => false,
                'message' => "Les numéros de série suivants existent déjà : " . implode(', ', $existing),
            ], 422);
        }

        return true;
    }


    /**
     * Met à jour un approvisionnement en attente (lignes, fournisseur, statut).
     */
    public function update(Request $request, $id): \Illuminate\Http\JsonResponse
    {
        $storeId = $this->companyStoreResolver->resolveStoreAndCompany($request)['store_id'];
        $store = Store::find($storeId);
        $usesMeasurements = $store?->uses_measurements ?? true;

        $validated = $request->validate([
            'supplier_id'                     => 'required|exists:supplierproducts,id',
            'line_items'                      => 'required|array|min:1',
            'line_items.*.product_id'         => 'required|exists:products,id',
            'line_items.*.quantity'           => 'required|numeric|min:0.01',
            'line_items.*.purchase_price'     => 'required|numeric|min:0',
            'line_items.*.serial_numbers'     => 'nullable|array',
        ], [
            'supplier_id.required'                  => 'Le fournisseur est obligatoire.',
            'line_items.required'                   => "Les lignes d'approvisionnement sont obligatoires.",
            'line_items.array'                      => "Les lignes d'approvisionnement doivent être un tableau.",
            'line_items.*.product_id.required'      => 'Le produit est obligatoire pour chaque ligne.',
            'line_items.*.quantity.required'        => 'La quantité est obligatoire pour chaque ligne.',
            'line_items.*.quantity.numeric'         => 'La quantité doit être un nombre.',
            'line_items.*.purchase_price.required'  => "Le prix d'achat est obligatoire pour chaque ligne.",
            'line_items.*.purchase_price.numeric'   => "Le prix d'achat doit être un nombre.",
        ]);

        if (!$usesMeasurements) {
            foreach ($validated['line_items'] as $item) {
                if ((float) $item['quantity'] !== (float) (int) $item['quantity']) {
                    return response()->json([
                        'status' => false,
                        'message' => 'La quantité doit être un nombre entier pour une boutique sans gestion de mesures.'
                    ], 422);
                }
            }
        }

        DB::beginTransaction();

        try {
            // ── Récupération et vérification ──────────────────────────────────────
            $supply = Supply::with('supplyLineItems')
                ->where('id', $id)
                ->where('store_id', $storeId)
                ->firstOrFail();

            if ($supply->status !== 'pending') {
                return response()->json([
                    'status'  => false,
                    'message' => 'Seuls les approvisionnements en attente peuvent être modifiés.',
                ], 422);
            }

            // ── 1. Suppression des anciennes lignes et S/N (forceDelete : évite violation unique)
            $oldLineIds = $supply->supplyLineItems->pluck('id');
            if ($oldLineIds->isNotEmpty()) {
                SerialNumber::withTrashed()
                    ->whereIn('supply_line_item_id', $oldLineIds)
                    ->forceDelete();
            }
            $supply->supplyLineItems()->delete();

            // ── 2. Mise à jour de l'en-tête ───────────────────────────────────────
            $supply->update([
                'supplier_id' => $validated['supplier_id'],
                'user_id'     => Auth::id(),
            ]);

            // ── 3. Validation des S/N APRÈS suppression des anciens ───────────────
            //    On passe $supply->id pour exclure les éventuels S/N résiduels
            foreach ($validated['line_items'] as $item) {
                $product = Product::where('id', $item['product_id'])
                    ->where('store_id', $storeId)
                    ->firstOrFail();
                $serialCheck = $this->handleSerialNumbers($product, $item, $supply->id);

                if ($serialCheck instanceof \Illuminate\Http\JsonResponse) {
                    DB::rollBack();
                    return $serialCheck;
                }
            }

            // ── 4. Recréation des lignes et S/N ───────────────────────────────────
            $totalAmount    = 0;
            $serialsToInsert = [];

            foreach ($validated['line_items'] as $item) {
                $product = Product::where('id', $item['product_id'])
                    ->where('store_id', $storeId)
                    ->firstOrFail();

                $line = $supply->supplyLineItems()->create([
                    'product_id'         => $item['product_id'],
                    'quantity'           => $item['quantity'],
                    'base_unit_quantity' => $item['quantity'],
                    'purchase_price'     => $item['purchase_price'],
                ]);

                // Prépare les S/N en batch pour limiter les requêtes
                if (!empty($item['serial_numbers']) && $product->require_serial_number) {
                    $now = now();
                    foreach ($item['serial_numbers'] as $sn) {
                        $serialsToInsert[] = [
                            'product_id'          => $product->id,
                            'supply_line_item_id' => $line->id,
                            'serial_number'       => $sn,
                            'is_sold'             => false,
                            'created_at'          => $now,
                            'updated_at'          => $now,
                        ];
                    }
                }

                $totalAmount += $item['quantity'] * $item['purchase_price'];
            }

            // Insertion en masse des S/N (1 requête au lieu de N)
            if (!empty($serialsToInsert)) {
                SerialNumber::insert($serialsToInsert);
            }

            // ── 5. Recalcul du montant total ──────────────────────────────────────
            $supply->update(['total_amount' => $totalAmount]);

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Approvisionnement mis à jour avec succès.',
                'data'    => new SupplyResource($supply->fresh(['supplier', 'user', 'store', 'supplyLineItems'])),
            ]);

        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Approvisionnement introuvable.',
            ], 404);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur dans SupplyController@update : ' . $e->getMessage());

            return response()->json([
                'status'  => false,
                'message' => "Une erreur est survenue lors de la mise à jour de l'approvisionnement.",
            ], 500);
        }
    }
}
