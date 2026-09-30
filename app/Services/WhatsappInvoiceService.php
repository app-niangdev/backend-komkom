<?php

namespace App\Services;

use App\Jobs\SendWhatsappInvoiceDocument;
use App\Models\Invoice;
use App\Models\PaymentReceipt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envoi au client, sur WhatsApp, de sa facture après une vente et du reçu de chaque paiement
 * ultérieur. Uniquement pour les boutiques où l'administrateur l'a activé et quand le client
 * a laissé un numéro exploitable. L'envoi part après la réponse HTTP : la caisse n'attend pas WAHA,
 * et un échec d'envoi est journalisé sans jamais remettre en cause la vente.
 */
class WhatsappInvoiceService
{
    public function __construct(protected WahaService $waha, protected InvoiceDocumentService $documents)
    {
    }

    /** Programme l'envoi de la facture ; vrai si un envoi est prévu. */
    public function queueInvoice(Invoice $invoice): bool
    {
        if (!$this->chatIdFor($invoice)) {
            return false;
        }
        SendWhatsappInvoiceDocument::dispatchAfterResponse($invoice->id);

        return true;
    }

    /** Programme l'envoi du reçu d'un paiement ; vrai si un envoi est prévu. */
    public function queueReceipt(PaymentReceipt $receipt): bool
    {
        if (!$receipt->invoice || !$this->chatIdFor($receipt->invoice)) {
            return false;
        }
        SendWhatsappInvoiceDocument::dispatchAfterResponse($receipt->invoice_id, $receipt->id);

        return true;
    }

    public function send(Invoice $invoice, ?PaymentReceipt $receipt = null): void
    {
        $chatId = $this->chatIdFor($invoice);
        if (!$chatId) {
            return;
        }

        try {
            $currency = config('subscriptions.default_currency', 'XOF');
            $money = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' ' . $currency;
            $store = $invoice->store->name;
            $name = $invoice->customer?->name;
            $hello = 'Bonjour' . ($name ? ' ' . $name : '') . ',';

            if ($receipt) {
                $pdf = $this->documents->receiptPdf($receipt);
                $filename = 'Recu-' . $invoice->invoice_number . '-' . $receipt->id . '.pdf';
                $balance = (int) $invoice->fresh()->balance;
                $caption = "{$hello}\n\nNous avons bien reçu votre paiement de *{$money($receipt->amount)}* chez *{$store}* "
                    . "(facture {$invoice->invoice_number}).\n"
                    . ($balance > 0 ? "Reste à payer : *{$money($balance)}*." : 'Votre facture est entièrement réglée.')
                    . "\n\nVotre reçu est joint à ce message. Merci de votre confiance !";
            } else {
                $pdf = $this->documents->invoicePdf($invoice);
                $filename = 'Facture-' . $invoice->invoice_number . '.pdf';
                $caption = "{$hello}\n\nMerci pour votre achat chez *{$store}*.\n"
                    . "Facture {$invoice->invoice_number} : *{$money($invoice->amount_total)}*"
                    . ($invoice->balance > 0 ? "\nPayé : {$money($invoice->amount_paid)} · Reste à payer : *{$money($invoice->balance)}*" : ' (réglée)')
                    . "\n\nVotre facture est jointe à ce message.";
            }

            $this->waha->sendFile($chatId, $pdf, $filename, 'application/pdf', $caption);
        } catch (Throwable $e) {
            Log::warning('Envoi WhatsApp de la facture impossible', [
                'invoice_id' => $invoice->id,
                'receipt_id' => $receipt?->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Destinataire WAHA, ou null si l'envoi ne s'applique pas à cette facture. */
    private function chatIdFor(Invoice $invoice): ?string
    {
        $invoice->loadMissing(['store', 'customer']);

        if (!$invoice->store?->whatsapp_invoices_enabled || !$this->waha->isConfigured()
            || $invoice->is_cancelled || $invoice->invoice_status === 'cancelled') {
            return null;
        }

        return $this->waha->chatId($invoice->customer?->phone);
    }
}
