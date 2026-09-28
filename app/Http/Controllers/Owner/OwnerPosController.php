<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SerialNumber;
use App\Models\Store;
use App\Services\OwnerScopeService;
use App\Services\SaleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Caisse (nouvelle vente) d'une boutique : catalogue vendable, clients, numéros de série
 * disponibles et encaissement. La boutique est toujours explicite (store_id).
 */
class OwnerPosController extends Controller
{
    public function __construct(protected OwnerScopeService $scope, protected SaleService $sales)
    {
    }

    /** Catalogue de la boutique avec prix et stock par unité de vente ; les ruptures en fin de liste. */
    public function products(Request $request)
    {
        $validated = $request->validate([
            'store_id' => 'required|integer',
            'search' => 'nullable|string|max:100',
            'category_id' => 'nullable|integer',
            'perPage' => 'nullable|integer|min:1|max:60',
        ]);
        $store = $this->ownedStore($request, $validated['store_id']);

        $products = Product::query()
            ->with(['category:id,name', 'media', 'unitOfMeasures:id,product_id,name,price,conversion_factor,is_base_unit'])
            ->where('store_id', $store->id)
            ->when($validated['search'] ?? null, fn ($q, $s) => $q->where('name', 'ILIKE', "%{$s}%"))
            ->when($validated['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->orderByRaw('CASE WHEN base_unit_quantity > 0 THEN 0 ELSE 1 END')
            ->orderBy('name')
            ->paginate($validated['perPage'] ?? 24);

        return response()->json([
            'data' => collect($products->items())->map(fn (Product $p) => $this->productPayload($p)),
            'meta' => [
                'current_page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'last_page' => $products->lastPage(),
            ],
            'categories' => Category::where('store_id', $store->id)->orderBy('name')->get(['id', 'name']),
            'uses_measurements' => (bool) ($store->uses_measurements ?? true),
            'currency' => config('subscriptions.default_currency', 'XOF'),
        ]);
    }

    /** Numéros de série vendables d'un produit (non vendus, non réservés, approvisionnement reçu). */
    public function serials(Request $request, $productId)
    {
        $store = $this->ownedStore($request, $request->validate(['store_id' => 'required|integer'])['store_id']);
        $product = Product::where('store_id', $store->id)->findOrFail($productId);

        return response()->json([
            'data' => SerialNumber::where('product_id', $product->id)
                ->available()
                ->orderBy('serial_number')
                ->pluck('serial_number'),
        ]);
    }

    /** Recherche rapide de clients de la boutique, avec leur reste à payer. */
    public function customers(Request $request)
    {
        $validated = $request->validate([
            'store_id' => 'required|integer',
            'search' => 'nullable|string|max:100',
        ]);
        $store = $this->ownedStore($request, $validated['store_id']);

        $balances = DB::table('invoices')
            ->whereNull('deleted_at')
            ->where('is_cancelled', false)
            ->groupBy('customer_id')
            ->selectRaw('customer_id, SUM(balance) AS balance_due');

        $customers = Customer::query()
            ->where('customers.store_id', $store->id)
            ->when($validated['search'] ?? null, fn ($q, $s) => $q->where(fn ($sub) => $sub
                ->where('name', 'ILIKE', "%{$s}%")
                ->orWhere('phone', 'ILIKE', "%{$s}%")))
            ->leftJoinSub($balances, 'bal', 'bal.customer_id', '=', 'customers.id')
            ->orderBy('name')
            ->limit(15)
            ->get(['customers.id', 'customers.name', 'customers.phone', 'bal.balance_due']);

        return response()->json([
            'data' => $customers->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'balance_due' => (float) ($c->balance_due ?? 0),
            ]),
        ]);
    }

    /** Encaissement : vente validée, stock sorti, facture émise et paiement enregistré. */
    public function checkout(Request $request)
    {
        $validated = $request->validate([
            'store_id' => 'required|integer',
            'customer.mode' => 'required|in:anonymous,existing,new',
            'customer.id' => 'required_if:customer.mode,existing|nullable|integer',
            'customer.name' => 'required_if:customer.mode,new|nullable|string|min:2|max:100',
            'customer.phone' => ['required_if:customer.mode,new', 'nullable', 'string', 'regex:/^\+?[0-9 ]{9,15}$/'],
            'items' => 'required|array|min:1|max:200',
            'items.*.product_id' => 'required|integer',
            'items.*.unit_of_measure_id' => 'nullable|integer',
            'items.*.quantity' => 'required|numeric|min:0.001|max:1000000',
            'items.*.unit_price' => 'required|numeric|min:0|max:1000000000',
            'items.*.serial_numbers' => 'nullable|array',
            'items.*.serial_numbers.*' => 'string|max:100',
            'discount' => 'nullable|integer|min:0',
            'payment.amount' => 'required|numeric|min:0',
            'payment.payment_type' => ['required', Rule::in(SaleService::PAYMENT_TYPES)],
        ], [
            'store_id.required' => 'Choisissez la boutique où enregistrer la vente.',
            'customer.name.required_if' => 'Le nom du nouveau client est obligatoire.',
            'customer.phone.required_if' => 'Le téléphone du nouveau client est obligatoire.',
            'customer.phone.regex' => 'Le téléphone doit contenir entre 9 et 15 chiffres.',
            'customer.id.required_if' => 'Choisissez le client.',
            'items.required' => 'Ajoutez au moins un article.',
            'payment.payment_type.in' => 'Moyen de paiement inconnu.',
        ]);

        $store = $this->ownedStore($request, $validated['store_id']);

        $sale = $this->sales->checkout(
            $store,
            $request->user(),
            $validated['customer'],
            $validated['items'],
            (int) ($validated['discount'] ?? 0),
            $validated['payment']
        );
        $sale->load('invoice');

        return response()->json([
            'message' => $sale->invoice->balance > 0
                ? 'Vente enregistrée. Reste à payer : ' . number_format($sale->invoice->balance, 0, ',', ' ') . '.'
                : 'Vente encaissée.',
            'data' => [
                'sale_id' => $sale->id,
                'sale_number' => $sale->sale_number,
                'invoice_id' => $sale->invoice->id,
                'invoice_number' => $sale->invoice->invoice_number,
                'total_amount' => (float) $sale->total_amount,
                'amount_paid' => (float) $sale->invoice->amount_paid,
                'balance' => (float) $sale->invoice->balance,
            ],
        ], 201);
    }

    private function productPayload(Product $p): array
    {
        $stock = (float) $p->base_unit_quantity;
        $base = $p->unitOfMeasures->firstWhere('is_base_unit', true);

        return [
            'id' => $p->id,
            'name' => $p->name,
            'image_url' => $p->getFirstMediaUrl('image') ?: null,
            'category_id' => $p->category_id,
            'category' => $p->category?->name,
            'base_unit' => $p->base_unit,
            'stock' => $stock,
            'stock_state' => $stock <= 0 ? 'out' : ($stock <= $p->alert_threshold ? 'low' : 'ok'),
            'require_serial_number' => (bool) $p->require_serial_number,
            // Unité de base en premier ; `id` null quand le produit n'a aucune unité enregistrée
            'units' => collect([$base ? null : ['id' => null, 'name' => $p->base_unit, 'price' => 0.0, 'factor' => 1.0, 'is_base' => true]])
                ->filter()
                ->concat($p->unitOfMeasures
                    ->sortByDesc('is_base_unit')
                    ->map(fn ($u) => [
                        'id' => $u->id,
                        'name' => $u->name,
                        'price' => (float) $u->price,
                        'factor' => (float) $u->conversion_factor,
                        'is_base' => (bool) $u->is_base_unit,
                    ]))
                ->values(),
        ];
    }

    private function ownedStore(Request $request, $storeId): Store
    {
        $store = $this->scope->stores($request->user())->firstWhere('id', (int) $storeId);
        abort_if(!$store, 422, 'La boutique choisie ne fait pas partie de vos boutiques.');

        return $store;
    }
}
