<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Http\Request;
use App\Http\Resources\SaleResource;
use App\Models\Invoice;
use App\Models\PaymentReceipt;
use App\Models\Product;
use App\Models\SaleLineItem;
use App\Models\SerialNumber;
use App\Models\Store;
use App\Services\CompanyStoreResolverService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
    protected $companyStoreResolver;

    public function __construct(CompanyStoreResolverService $companyStoreResolver)
    {
        $this->companyStoreResolver = $companyStoreResolver;
    }
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('perPage', 10);
            $page = $request->input('page', 1);

            $context = $this->companyStoreResolver->resolveStoreAndCompany($request);
            $storeId = $context['store_id'];

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Impossible de déterminer la boutique associée à cet utilisateur.'
                ], 422);
            }

            $query = Sale::query()
                ->where('store_id', $storeId)
                ->with('invoice');

            // Si l'utilisateur a le rôle Seller, filtrer uniquement ses ventes
            $user = Auth::user();
            if ($user && $user->role->name == 'Seller') {
                $query->where('seller_id', $user->id);
            }

            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;

                // Rechercher les customer_id correspondant au client (table customers)
                $customerIds = Customer::where('store_id', $storeId)
                    ->where(function ($q) use ($search) {
                        $q->where('name', 'ilike', "%{$search}%")
                            ->orWhere('phone', 'ilike', "%{$search}%")
                            ->orWhere('email', 'ilike', "%{$search}%");
                    })->pluck('id')->toArray();

                $query->where(function ($q) use ($customerIds, $search) {
                    // Recherche par client rattaché (nom / téléphone / email).
                    // Le client est nullable : on ne filtre que s'il y a des
                    // correspondances dans la table customers.
                    if (!empty($customerIds)) {
                        $q->whereIn('customer_id', $customerIds);
                    }

                    // Recherche par numéro de facture ou par nom conservé sur la
                    // facture (« Anonyme » pour les ventes sans client).
                    $q->orWhereHas('invoice', function ($subQ) use ($search) {
                        $subQ->where('invoice_number', 'ilike', "%{$search}%")
                            ->orWhere('customer_name', 'ilike', "%{$search}%");
                    });
                });
            }

            $query->orderBy('created_at', 'desc');

            $sales = $query->paginate($perPage, ['*'], 'page', $page);


            return response()->json([
                'status' => 'success',
                'data' => SaleResource::collection($sales),
                'meta' => [
                    'current_page' => $sales->currentPage(),
                    'per_page' => $sales->perPage(),
                    'total' => $sales->total(),
                    'last_page' => $sales->lastPage(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la récupération des ventes.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $context = $this->companyStoreResolver->resolveStoreAndCompany($request);
        $storeId = $context['store_id'] ?? null;

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Impossible de déterminer la boutique associée à cet utilisateur.'
            ], 422);
        }

        $store = Store::find($storeId);
        $usesMeasurements = $store?->uses_measurements ?? true;

        $validator = Validator::make($request->all(), [
            'customer_type' => 'required|in:anonymous,existing,new',
            'customer_id' => 'required_if:customer_type,existing|exists:customers,id',
            'new_customer' => 'required_if:customer_type,new|array',
            'discount' => 'nullable|integer|min:0',
            'new_customer.name' => 'required_if:customer_type,new|string',
            'new_customer.phone' => 'required_if:customer_type,new|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.unit_price_at_sale' => 'required|numeric|min:0',
            'items.*.serial_numbers' => 'array',
            'items.*.serial_numbers.*' => 'string',
        ], [
            'customer_type.required' => 'Le type de client est obligatoire',
            'customer_type.in' => 'Le type de client doit être anonymous, existing ou new',
            'customer_id.required_if' => 'L\'ID client est obligatoire pour un client existant',
            'new_customer.required_if' => 'Les informations client sont obligatoires pour un nouveau client',
            'new_customer.name.required_if' => 'Le nom du client est obligatoire pour un nouveau client',
            'new_customer.phone.required_if' => 'Le téléphone du client est obligatoire pour un nouveau client',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->first()], 422);
        }

        if (empty($request->customer_id) && empty($request->new_customer) && ($request->customer_type != 'anonymous')) {
            return response()->json(['errors' => 'Un client existant (customer_id) ou les informations d\'un nouveau client (new_customer) sont requis'], 422);
        }

        if (!empty($request->customer_id) && !empty($request->new_customer)) {
            return response()->json(['errors' => 'Veuillez fournir soit un client existant (customer_id) soit les informations d\'un nouveau client (new_customer), pas les deux'], 422);
        }

        try {
            foreach ($request->items as $item) {
                $product = Product::where('id', $item['product_id'])
                    ->where('store_id', $storeId)
                    ->first();

                if (!$product) {
                    return response()->json([
                        'status' => false,
                        'message' => "Produit introuvable pour cette boutique: {$item['product_id']}"
                    ], 422);
                }

                if (!$usesMeasurements && (float) $item['quantity'] !== (float) (int) $item['quantity']) {
                    return response()->json([
                        'status' => false,
                        'message' => "La quantité du produit {$product->name} doit être un nombre entier pour une boutique sans gestion de mesures."
                    ], 422);
                }

                if ($product->require_serial_number && (float) $item['quantity'] !== (float) (int) $item['quantity']) {
                    return response()->json([
                        'status' => false,
                        'message' => "La quantité du produit {$product->name} doit être entière car il utilise des numéros de série."
                    ], 422);
                }
            }

            return DB::transaction(function () use ($request, $storeId) {
                $this->reserveStockForItems($request->items, $storeId);

                $customer_id = null;
                if ($request->customer_type !== 'anonymous') {
                    $customer_id = $this->getOrCreateCustomer($request, $storeId);
                }

                return $this->createSaleWithLineItems($request, $customer_id, $storeId);
            });
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erreur lors de la création de la vente',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function getOrCreateCustomer(Request $request, int $storeId): int
    {
        if (!empty($request->customer_id)) {
            $customer = Customer::where('id', $request->customer_id)
                ->where('store_id', $storeId)
                ->firstOrFail();
            return $customer->id;
        }

        $customer = Customer::create([
            'name' => $request->new_customer['name'],
            'email' => $request->new_customer['email'] ?? null,
            'phone' => $request->new_customer['phone'],
            'address' => $request->new_customer['address'] ?? 'N/A',
            'store_id' => $storeId
        ]);

        return $customer->id;
    }

    /**
     * Vérifie et réserve le stock dès la création de la vente (statut pending).
     * Le stock est décrémenté ici pour éviter qu'une autre vente le consomme avant validation.
     */
    private function reserveStockForItems(array $items, int $storeId): void
    {
        foreach ($items as $item) {
            $product = Product::where('id', $item['product_id'])
                ->where('store_id', $storeId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($product->require_serial_number) {
                $available = $product->countAvailableSerials();
                if ($available < $item['quantity']) {
                    throw ValidationException::withMessages([
                        'items' => ["Stock insuffisant pour le produit: {$product->name}. Numéros de série disponibles: {$available}"]
                    ]);
                }
                continue;
            }

            if ($product->base_unit_quantity < $item['quantity']) {
                throw ValidationException::withMessages([
                    'items' => ["Stock insuffisant pour le produit: {$product->name}. Stock disponible: {$product->base_unit_quantity}"]
                ]);
            }

            $product->decrement('base_unit_quantity', $item['quantity']);
        }
    }

    private function createSaleWithLineItems(Request $request, ?int $customer_id, int $storeId)
    {
        DB::beginTransaction();

        try {
            $items = $request->items;

            // Générer le numéro de vente
            $saleNumber = $this->generateSaleNumber($storeId);

            $sale = Sale::create([
                'store_id' => $storeId,
                'seller_id' => Auth::id(),
                'sale_number' => $saleNumber,
                'gross_amount' => 0,
                'discount' => 0,
                'total_amount' => 0,
                'status' => 'pending',
                'customer_id' => $customer_id,
            ]);

            $productIds = collect($items)->pluck('product_id')->toArray();
            $products = Product::whereIn('id', $productIds)
                ->where('store_id', $storeId)
                ->get()
                ->keyBy('id');

            $totalAmount = 0;
            $grossAmount = 0;

            foreach ($items as $item) {
                $product = $products[$item['product_id']] ?? null;
                if (!$product) {
                    throw new \Exception("Produit non trouvé: {$item['product_id']}");
                }

                // Vérifier si le produit requiert des numéros de série
                if ($product->require_serial_number) {
                    $serialNumbers = $item['serial_numbers'] ?? [];
                    if (empty($serialNumbers) || count($serialNumbers) !== $item['quantity']) {
                        throw new \Exception("Le produit {$product->name} nécessite {$item['quantity']} numéro(s) de série.");
                    }

                    // Vérifier la disponibilité des numéros de série
                    if (!$this->areSerialNumbersAvailable($serialNumbers, $product->id, $storeId)) {
                        throw new \Exception("Les numéros de série fournis ne sont pas tous disponibles pour le produit {$product->name}.");
                    }
                }

                $unitPriceAtSale = $item['unit_price_at_sale'];
                $lineAmount = $item['quantity'] * $unitPriceAtSale;
                $totalAmount += $lineAmount;

                // Calcul du montant brut - utiliser le prix de l'unité de base si disponible
                $baseUnit = $product->unitOfMeasures()->where('is_base_unit', true)->first();
                $productBasePrice = $baseUnit ? $baseUnit->price : $unitPriceAtSale;
                $grossLineAmount = $item['quantity'] * $productBasePrice;
                $grossAmount += $grossLineAmount;

                $saleLineItem = SaleLineItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $unitPriceAtSale,
                    'subtotal' => $lineAmount,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                // Associer les numéros de série au line item (sans les marquer comme vendus)
                if ($product->require_serial_number) {
                    $serialNumbers = $item['serial_numbers'] ?? [];

                    SerialNumber::whereIn('serial_number', $serialNumbers)
                        ->where('product_id', $product->id)
                        ->update([
                            'sale_line_item_id' => $saleLineItem->id
                        ]);

                    $product->syncStockFromSerialNumbers();
                }
            }

            $sale->update([
                'gross_amount' => $grossAmount,
                'total_amount' => $totalAmount,
                'discount' => $grossAmount - $totalAmount,
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Vente créée avec succès en attente de validation',
                'sale_id' => $sale->id,
                'sale_number' => $sale->sale_number,
                'sale_status' => 'pending',
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la création de la vente: ' . $e->getMessage()
            ], 500);
        }
    }

    private function generateSaleNumber(int $storeId): string
    {
        $prefix = 'VNT';
        $date = now()->format('Ymd');

        // Compter les ventes du jour pour ce magasin
        $count = Sale::where('store_id', $storeId)
            ->whereDate('created_at', now()->toDateString())
            ->count();

        $sequence = str_pad($count + 1, 4, '0', STR_PAD_LEFT);

        return "{$prefix}-{$date}-{$storeId}-{$sequence}";
    }

    private function areSerialNumbersAvailable(array $serialNumbers, int $productId, int $storeId): bool
    {
        // Vérifier que tous les numéros de série existent et sont disponibles
        $availableCount = SerialNumber::whereIn('serial_number', $serialNumbers)
            ->where('product_id', $productId)
            ->available()
            ->whereHas('product', function ($query) use ($storeId) {
                $query->where('store_id', $storeId);
            })
            ->count();

        // Tous les numéros de série doivent être disponibles
        return $availableCount === count($serialNumbers);
    }

    private function generateInvoiceForSale(Sale $sale)
    {
        // Le client est nullable : on conserve son nom sur la facture,
        // ou « Anonyme » quand aucun client n'est rattaché.
        $customerId = $sale->customer->id ?? null;
        $customerName = $sale->customer->name ?? 'Anonyme';
        return Invoice::create([
            'store_id' => $sale->store_id,
            'sale_id' => $sale->id,
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'invoice_status' => 'no_paid',
            'balance' => $sale->total_amount,
            'amount_paid' => 0,
            'amount_total' => $sale->total_amount,
            'invoice_number' => Invoice::generateInvoiceNumber()
        ]);
    }

    public function show($id)
    {
        $sale = Sale::with(['customer', 'saleLineItems.product', 'saleLineItems.serialNumbers'])->findOrFail($id);
        return response()->json(['data' => $sale]);
    }


    public function validateSale(Request $request, $id)
    {
        Log::info($request->all());
        try {
            $context = $this->companyStoreResolver->resolveStoreAndCompany($request);
            $storeId = $context['store_id'];

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Impossible de déterminer la boutique associée à cet utilisateur.'
                ], 422);
            }

            $sale = Sale::with(['saleLineItems.product'])
                ->where('id', $id)
                ->where('store_id', $storeId)
                ->firstOrFail();

            if ($sale->status !== 'pending') {
                return response()->json([
                    'status' => false,
                    'message' => 'Seules les ventes en attente peuvent être validées. Statut actuel: ' . $sale->status
                ], 422);
            }

            return DB::transaction(function () use ($sale, $request) {
                // Le stock a déjà été réservé à la création (pending) — vérifier les numéros de série
                foreach ($sale->saleLineItems as $lineItem) {
                    $product = $lineItem->product;

                    // Si le produit requiert des numéros de série
                    if ($product->require_serial_number) {
                        // Récupérer les numéros de série déjà associés à ce line item
                        $associatedSerialNumbers = SerialNumber::where('sale_line_item_id', $lineItem->id)
                            ->where('product_id', $product->id)
                            ->get();

                        if ($associatedSerialNumbers->count() !== $lineItem->quantity) {
                            throw new \Exception("Le produit {$product->name} nécessite {$lineItem->quantity} numéro(s) de série. Seulement {$associatedSerialNumbers->count()} ont été associés.");
                        }

                        // Vérifier que les numéros de série sont toujours disponibles
                        $unavailableSerials = $associatedSerialNumbers->filter(function ($serial) {
                            return $serial->is_sold;
                        });

                        if ($unavailableSerials->count() > 0) {
                            throw new \Exception("Certains numéros de série pour le produit {$product->name} ont déjà été vendus.");
                        }
                    }
                }

                // Marquer les serial numbers comme vendus (stock déjà réservé à la création)
                foreach ($sale->saleLineItems as $lineItem) {
                    $product = $lineItem->product;

                    // Marquer les numéros de série déjà associés comme vendus
                    if ($product->require_serial_number) {
                        SerialNumber::where('sale_line_item_id', $lineItem->id)
                            ->where('product_id', $product->id)
                            ->update([
                                'is_sold' => true
                            ]);

                        $product->syncStockFromSerialNumbers();
                    }
                }

                // Changer le statut de la vente
                $sale->status = 'confirmed';
                $sale->save();

                // Créer la facture avec le statut 'no_paid' (une vente validée n'est pas forcément payée)
                $invoice = $this->generateInvoiceForSale($sale);

                // Si un paiement est fourni lors de la validation, l'enregistrer
                if (isset($request->payment) && isset($request->payment['amount']) && $request->payment['amount'] > 0) {
                    $paymentData = [
                        'date' => now(),
                        'amount' => $request->payment['amount'],
                        'invoice_id' => $invoice->id,
                        'payment_type' => $request->payment['payment_type'] ?? 'cash',
                        'phone_number' => $request->payment['phone_number'] ?? null,
                        'user_id' => Auth::id(),
                    ];

                    PaymentReceipt::create($paymentData);

                    // Mettre à jour les montants payés et le solde
                    $invoice->amount_paid = $paymentData['amount'];
                    $invoice->balance = $sale->total_amount - $paymentData['amount'];

                    // Déterminer le statut en fonction du paiement
                    $invoice->invoice_status = match (true) {
                        $invoice->balance == 0 => 'paid',
                        $invoice->balance == $sale->total_amount => 'no_paid',
                        default => 'partial',
                    };

                    $invoice->save();
                }
                // Sinon, la facture reste 'no_paid' avec balance = total_amount

                return response()->json([
                    'status' => 'success',
                    'message' => 'Vente validée avec succès. Stocks mis à jour.',
                    'sale_id' => $sale->id,
                    'sale_status' => $sale->status,
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                ], 200);
            });
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la validation de la vente: ' . $e->getMessage()
            ], 500);
        }
    }

    public function cancel(Request $request, $id)
{
    try {
        $context = $this->companyStoreResolver->resolveStoreAndCompany($request);
        $storeId = $context['store_id'];

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Impossible de déterminer la boutique associée à cet utilisateur.'
            ], 422);
        }

        $sale = Sale::with(['saleLineItems.product', 'saleLineItems.serialNumbers', 'invoice.paymentReceipts', 'customer'])
            ->where('id', $id)
            ->where('store_id', $storeId)
            ->firstOrFail();

        if ($sale->status === 'cancelled') {
            return response()->json([
                'status' => false,
                'message' => 'Cette vente est déjà annulée'
            ], 422);
        }

        return DB::transaction(function () use ($sale, $request) {
            // Sauvegarder le statut actuel avant modification
            $previousStatus = $sale->status;
            $currentUserId = Auth::id();

            $sale->status = 'cancelled';
            $sale->save();

            // Restaurer les stocks et serial numbers selon le statut
            if ($previousStatus === 'confirmed') {
                foreach ($sale->saleLineItems as $lineItem) {
                    $product = $lineItem->product;

                    if ($product->require_serial_number) {
                        SerialNumber::where('sale_line_item_id', $lineItem->id)
                            ->update([
                                'is_sold' => false,
                                'sale_line_item_id' => null
                            ]);
                        $product->syncStockFromSerialNumbers();
                    } else {
                        $product->increment('base_unit_quantity', $lineItem->stockQuantity());
                    }
                }
            } elseif ($previousStatus === 'pending') {
                foreach ($sale->saleLineItems as $lineItem) {
                    $product = $lineItem->product;

                    if ($product->require_serial_number) {
                        SerialNumber::where('sale_line_item_id', $lineItem->id)
                            ->update([
                                'sale_line_item_id' => null
                            ]);
                        $product->syncStockFromSerialNumbers();
                    } else {
                        $product->increment('base_unit_quantity', $lineItem->stockQuantity());
                    }
                }
            }

            // GESTION DE LA FACTURE
            if ($sale->invoice) {
                // Si une facture existe déjà (vente confirmée)
                // Annuler les reçus de paiement
                foreach ($sale->invoice->paymentReceipts as $receipt) {
                    $receipt->status = 'cancelled';
                    $receipt->save();
                }

                // Marquer la facture existante comme annulée
                $sale->invoice->markAsCancelled($currentUserId);
            } else {
                // Si aucune facture n'existe (vente en attente)
                // Créer une facture d'annulation avec montant 0 ou le montant original.
                // Le client est nullable (ventes anonymes) : on conserve le nom
                // du client, ou « Anonyme » quand aucun client n'est rattaché.
                $customerName = $sale->customer->name ?? 'Anonyme';

                $invoice = Invoice::create([
                    'store_id' => $sale->store_id,
                    'sale_id' => $sale->id,
                    'customer_id' => $sale->customer_id,
                    'customer_name' => $customerName,
                    'invoice_status' => 'cancelled',
                    'is_cancelled' => true,
                    'balance' => 0,
                    'amount_paid' => 0,
                    'amount_total' => $sale->total_amount, // Conserver le montant pour la trace
                    'cancelled_at' => now(),
                    'cancelled_by' => $currentUserId,
                    'invoice_number' => Invoice::generateInvoiceNumber()
                ]);
            }

            $message = 'Vente annulée avec succès';
            if ($previousStatus === 'confirmed') {
                $message .= ' (stocks et numéros de série restaurés)';
            } elseif ($previousStatus === 'pending') {
                $message .= ' (numéros de série libérés)';
            }

            return response()->json([
                'status' => 'success',
                'message' => $message,
                'sale_id' => $sale->id,
                'previous_status' => $previousStatus,
                'new_status' => 'cancelled',
                'has_invoice' => $sale->invoice !== null || isset($invoice),
            ]);
        });
    } catch (\Exception $e) {
        return response()->json([
            'error' => 'Erreur lors de l\'annulation de la vente',
            'message' => $e->getMessage()
        ], 500);
    }
}

}
