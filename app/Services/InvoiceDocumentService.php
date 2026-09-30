<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PaymentReceipt;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * PDF des documents remis au client : facture A4 (articles, paiements, reste dû)
 * et reçu d'un paiement. Même contenu que les documents imprimés depuis l'application.
 */
class InvoiceDocumentService
{
    public const PAYMENT_TYPE_LABELS = [
        'cash' => 'Espèces',
        'wave' => 'Wave',
        'OM' => 'Orange Money',
        'other' => 'Autre',
    ];

    public function __construct(protected PdfReportService $reports)
    {
    }

    public function invoicePdf(Invoice $invoice): string
    {
        return $this->render('invoice', $invoice);
    }

    public function receiptPdf(PaymentReceipt $receipt): string
    {
        return $this->render('receipt', $receipt->invoice, $receipt);
    }

    private function render(string $kind, Invoice $invoice, ?PaymentReceipt $receipt = null): string
    {
        $invoice->loadMissing([
            'store.company',
            'customer',
            'sale.seller:id,first_name,last_name',
            'sale.saleLineItems.product:id,name,base_unit',
            'sale.saleLineItems.serialNumbers',
            'paymentReceipts' => fn ($q) => $q->orderBy('date')->orderBy('id'),
            'paymentReceipts.user:id,first_name,last_name',
        ]);
        $store = $invoice->store;
        $sale = $invoice->sale;

        // Reçu : situation de la facture juste avant et juste après ce paiement
        $paidBefore = $receipt
            ? (int) $invoice->paymentReceipts->filter(fn ($p) => $p->id < $receipt->id)->sum('amount')
            : 0;
        $paidAfter = $paidBefore + (int) ($receipt?->amount ?? 0);

        return Pdf::loadView('pdf.invoice-document', [
            'kind' => $kind,
            'currency' => config('subscriptions.default_currency', 'XOF'),
            'paymentTypes' => self::PAYMENT_TYPE_LABELS,
            'issuer' => [
                'name' => $store?->name,
                'company' => $store?->company?->name,
                'slogan' => $store?->slogan ?: $store?->company?->slogan,
                'address' => $store?->address,
                'phones' => array_values(array_filter([$store?->phone_one, $store?->phone_two, $store?->phone_three])),
                'email' => $store?->email,
                'logo' => $store ? $this->reports->storeLogo($store) : null,
                'color' => $store?->effective_primary_color ?: '#1f2937',
            ],
            'invoice' => $invoice,
            'customer' => $invoice->customer?->name ?? ($invoice->customer_name && $invoice->customer_name !== 'Anonyme' ? $invoice->customer_name : 'Client anonyme'),
            'customerPhone' => $invoice->customer?->phone,
            'sale' => $sale,
            'seller' => $sale?->seller ? trim($sale->seller->first_name . ' ' . $sale->seller->last_name) : null,
            'items' => $sale?->saleLineItems ?? collect(),
            'receipt' => $receipt,
            'paidBefore' => $paidBefore,
            'paidAfter' => $paidAfter,
            'remaining' => max(0, (int) $invoice->amount_total - $paidAfter),
        ])
            ->setPaper('a4')
            ->setOption('isFontSubsettingEnabled', true)
            ->output();
    }
}
