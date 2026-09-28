<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\Request;
use App\Http\Resources\InvoiceResource;
use App\Models\PaymentReceipt;
use Illuminate\Support\Facades\DB;
use App\Services\CompanyStoreResolverService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class InvoiceController extends Controller
{
    protected $companyStoreResolver;

    public function __construct(CompanyStoreResolverService $companyStoreResolver)
    {
        $this->companyStoreResolver = $companyStoreResolver;
    }

    public function index(Request $request)
    {
        try {
            $search = $request->input('search');
            $status = $request->input('status');
            $perPage = (int) $request->input('perPage', 10);
            $page = (int) $request->input('page', 1);

            $context = $this->companyStoreResolver->resolveStoreAndCompany($request);
            $storeId = $context['store_id'];

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Impossible de déterminer la boutique associée à cet utilisateur.'
                ], 422);
            }

            $query = Invoice::query()->where('store_id', $storeId);

            // Si l'utilisateur a le rôle Seller, filtrer uniquement les factures de ses ventes
            $user = Auth::user();
            if ($user && $user->role->name == 'Seller') {
                $query->whereHas('sale', function ($q) use ($user) {
                    $q->where('seller_id', $user->id);
                });
            }

            $invoices = $query->when($search, function ($query) use ($search) {
                    $query->where('invoice_number', 'ilike', "%$search%")
                        ->orWhereHas('customer', function ($query) use ($search) {
                            $query->where('name', 'ilike', "%$search%");
                        })
                        ->orWhereHas('sale.customer', function ($query) use ($search) {
                            $query->where('name', 'ilike', "%$search%");
                        });
                })
                ->when($status, function ($query) use ($status) {
                    $query->where('invoice_status', $status);
                })
                ->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            return InvoiceResource::collection($invoices);
        } catch (\Exception $e) {
            Log::error('Erreur dans index (InvoiceController) : ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la récupération des factures.'
            ], 500);
        }
    }

    public function paidInvoice(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $user = Auth::user();

            $invoice = Invoice::find($id);
            if (!$invoice) {
                return response()->json([
                    'success' => false,
                    'message' => 'Facture non trouvée',
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'date'          => 'required|date',
                'amount'        => 'required|numeric|min:1',
                'payment_type'  => 'required|string|in:cash,OM,wave,other',
                'phone_number'  => 'nullable|string|regex:/^\+?[0-9]{9,15}$/',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors'  => $validator->errors(),
                ], 422);
            }

            $validatedData = $validator->validated();
            $validatedData['invoice_id'] = $id;
            $validatedData['user_id'] = $user->id;

            PaymentReceipt::create($validatedData);

            $totalPayments = $invoice->paymentReceipts()->sum('amount');
            $saleTotalAmount = $invoice->sale->total_amount;

            $invoice->invoice_status = $totalPayments < $saleTotalAmount ? 'partial' : 'paid';
            $invoice->amount_paid = $totalPayments;
            $invoice->balance = $saleTotalAmount - $totalPayments;
            $invoice->save();

            $sale = $invoice->sale;
            $sale->status_payment = $invoice->invoice_status;
            $sale->save();

            return response()->json([
                'success' => true,
                'message' => 'Paiement ajouté et facture mise à jour',
            ], 201);
        });
    }

    public function getInvoiceWithPayments($id)
    {
        $invoice = Invoice::with(['sale.customer', 'sale.saleLineItems.product', 'sale.saleLineItems.serialNumbers', 'paymentReceipts', 'customer'])->find($id);

        if (!$invoice) {
            return response()->json([
                'success' => false,
                'message' => 'Facture non trouvée',
            ], 404);
        }

        $responseData = [
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'customer_name' => $invoice->customer ? $invoice->customer->name : ($invoice->sale->customer ? $invoice->sale->customer->name : 'N/A'),
                'date' => $invoice->created_at,
                'balance' => $invoice->balance,
                'amount_paid' => $invoice->amount_paid,
                'invoice_status' => $invoice->invoice_status,
                'sale_id' => $invoice->sale_id,
                'amount_total' => $invoice->amount_total,
            ],
            'sale' => $invoice->sale ? [
                'id' => $invoice->sale->id,
                'sale_date' => $invoice->sale->created_at,
                'total_amount' => $invoice->sale->total_amount,
                'status' => $invoice->sale->status,
                'discount' => $invoice->sale->discount ?? 0,
                'line_items' => $invoice->sale->saleLineItems->map(function ($lineItem) {
                    return [
                        'id' => $lineItem->id,
                        'product_id' => $lineItem->product_id,
                        'product_name' => $lineItem->product ? $lineItem->product->name : 'N/A',
                        'quantity' => $lineItem->quantity,
                        'unit_price' => $lineItem->unit_price,
                        'subtotal' => $lineItem->subtotal,
                        'serial_numbers' => $lineItem->serialNumbers->pluck('serial_number'),
                    ];
                }),
            ] : null,
            'payments' => $invoice->paymentReceipts->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'amount' => $payment->amount,
                    'date' => $payment->date,
                    'payment_type' => $payment->payment_type,
                    'invoice_id' => $payment->invoice_id,
                    'user_id' => $payment->user_id,
                ];
            }),
            'customer' => $invoice->customer ? [
                'id' => $invoice->customer->id,
                'name' => $invoice->customer->name,
                'phone' => $invoice->customer->phone,
                'email' => $invoice->customer->email,
            ] : ($invoice->sale->customer ? [
                'id' => $invoice->sale->customer->id,
                'name' => $invoice->sale->customer->name,
                'phone' => $invoice->sale->customer->phone,
                'email' => $invoice->sale->customer->email,
            ] : null),
        ];

        return response()->json([
            'success' => true,
            'data' => $responseData,
        ], 200);
    }
}
// class InvoiceController extends Controller
// {
//     protected $companyStoreResolver;

//     public function __construct(CompanyStoreResolverService $companyStoreResolver)
//     {
//         $this->companyStoreResolver = $companyStoreResolver;
//     }

//     public function index(Request $request)
//     {
//         try {
//             $search = $request->input('search');
//             $status = $request->input('status');
//             $perPage = (int) $request->input('perPage', 10);
//             $page = (int) $request->input('page', 1);

//             $context = $this->companyStoreResolver->resolveStoreAndCompany($request);
//             $storeId = $context['store_id'];

//             if (!$storeId) {
//                 return response()->json([
//                     'status' => false,
//                     'message' => 'Impossible de déterminer la boutique associée à cet utilisateur.'
//                 ], 422);
//             }

//             $query = Invoice::query()->where('store_id', $storeId);

//             // Si l'utilisateur a le rôle Seller, filtrer uniquement les factures de ses ventes
//             $user = Auth::user();
//             if ($user && $user->role->name == 'Seller') {
//                 $query->whereHas('sale', function ($q) use ($user) {
//                     $q->where('seller_id', $user->id);
//                 });
//             }

//             $invoices = $query->when($search, function ($query) use ($search) {
//                     $query->where('invoice_number', 'ilike', "%$search%")
//                         ->orWhere('customer_name', 'ilike', "%$search%")
//                         ->orWhereHas('sale.customer', function ($query) use ($search) {
//                             $query->where('name', 'ilike', "%$search%");
//                         });
//                 })
//                 ->when($status, function ($query) use ($status) {
//                     $query->where('invoice_status', $status);
//                 })
//                 ->orderBy('created_at', 'desc')
//                 ->paginate($perPage, ['*'], 'page', $page);

//             return InvoiceResource::collection($invoices);
//         } catch (\Exception $e) {
//             Log::error('Erreur dans index (InvoiceController) : ' . $e->getMessage());

//             return response()->json([
//                 'status' => false,
//                 'message' => 'Une erreur est survenue lors de la récupération des factures.'
//             ], 500);
//         }
//     }

//     public function paidInvoice(Request $request, $id)
//     {
//         return DB::transaction(function () use ($request, $id) {
//             $user = Auth::user();

//             $invoice = Invoice::find($id);
//             if (!$invoice) {
//                 return response()->json([
//                     'success' => false,
//                     'message' => 'Facture non trouvée',
//                 ], 404);
//             }

//             $validator = Validator::make($request->all(), [
//                 'date'          => 'required|date',
//                 'amount'        => 'required|numeric|min:1',
//                 'payment_type'  => 'required|string|in:cash,OM,wave,other',
//                 'phone_number'  => 'nullable|string|regex:/^\+?[0-9]{9,15}$/',
//             ]);

//             if ($validator->fails()) {
//                 return response()->json([
//                     'success' => false,
//                     'errors'  => $validator->errors(),
//                 ], 422);
//             }

//             $validatedData = $validator->validated();
//             $validatedData['invoice_id'] = $id;
//             $validatedData['user_id'] = $user->id;

//             PaymentReceipt::create($validatedData);

//             $totalPayments = $invoice->paymentReceipts()->sum('amount');
//             $saleTotalAmount = $invoice->sale->total_amount;

//             $invoice->invoice_status = $totalPayments < $saleTotalAmount ? 'partial' : 'paid';
//             $invoice->amount_paid = $totalPayments;
//             $invoice->balance = $saleTotalAmount - $totalPayments;
//             $invoice->save();

//             $sale = $invoice->sale;
//             $sale->status_payment = $invoice->invoice_status;
//             $sale->save();

//             return response()->json([
//                 'success' => true,
//                 'message' => 'Paiement ajouté et facture mise à jour',
//             ], 201);
//         });
//     }

//     public function getInvoiceWithPayments($id)
//     {
//         $invoice = Invoice::with(['sale.customer', 'paymentReceipts'])->find($id);

//         if (!$invoice) {
//             return response()->json([
//                 'success' => false,
//                 'message' => 'Facture non trouvée',
//             ], 404);
//         }

//         $responseData = [
//             'invoice' => [
//                 'id' => $invoice->id,
//                 'invoice_number' => $invoice->invoice_number,
//                 'customer_name' => $invoice->sale->customer ? $invoice->sale->customer->name : 'N/A',
//                 'date' => $invoice->created_at,
//                 'balance' => $invoice->balance,
//                 'amount_paid' => $invoice->amount_paid,
//                 'invoice_status' => $invoice->invoice_status,
//                 'sale_id' => $invoice->sale_id,
//                 'amount_total' => $invoice->amount_total,
//             ],
//             'sale' => $invoice->sale ? [
//                 'id' => $invoice->sale->id,
//                 'sale_date' => $invoice->sale->created_at,
//                 'total_amount' => $invoice->sale->total_amount,
//                 'status' => $invoice->sale->status,
//                 'discount' => $invoice->sale->discount ?? 0,
//             ] : null,
//             'payments' => $invoice->paymentReceipts->map(function ($payment) {
//                 return [
//                     'id' => $payment->id,
//                     'amount' => $payment->amount,
//                     'date' => $payment->date,
//                     'payment_type' => $payment->payment_type,
//                     'invoice_id' => $payment->invoice_id,
//                     'user_id' => $payment->user_id,
//                 ];
//             }),
//             'customer' => $invoice->sale->customer ? [
//                 'id' => $invoice->sale->customer->id,
//                 'name' => $invoice->sale->customer->name,
//                 'phone' => $invoice->sale->customer->phone,
//                 'email' => $invoice->sale->customer->email,
//             ] : null,
//         ];

//         return response()->json([
//             'success' => true,
//             'data' => $responseData,
//         ], 200);
//     }
// }
