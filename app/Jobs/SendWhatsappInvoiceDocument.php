<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\PaymentReceipt;
use App\Services\WhatsappInvoiceService;
use Illuminate\Foundation\Bus\Dispatchable;

/** Envoi WhatsApp d'une facture (ou du reçu d'un de ses paiements), exécuté après la réponse HTTP. */
class SendWhatsappInvoiceDocument
{
    use Dispatchable;

    public function __construct(public int $invoiceId, public ?int $receiptId = null)
    {
    }

    public function handle(WhatsappInvoiceService $service): void
    {
        $invoice = Invoice::find($this->invoiceId);
        if (!$invoice) {
            return;
        }
        $receipt = $this->receiptId
            ? PaymentReceipt::where('invoice_id', $invoice->id)->find($this->receiptId)
            : null;
        if ($this->receiptId && !$receipt) {
            return;
        }

        $service->send($invoice, $receipt);
    }
}
