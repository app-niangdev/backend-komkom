<?php

namespace App\Services;

use App\Models\Quote;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * PDF d'un devis (même présentation que la facture) et envoi au client sur WhatsApp.
 * L'envoi WhatsApp suit le réglage de la boutique utilisé pour les factures.
 */
class QuoteDocumentService
{
    public function __construct(protected PdfReportService $reports, protected WahaService $waha)
    {
    }

    public function filename(Quote $quote): string
    {
        return 'Devis-' . $quote->quote_number . '.pdf';
    }

    public function pdf(Quote $quote): string
    {
        $quote->loadMissing(['store.company', 'customer', 'user:id,first_name,last_name', 'items']);
        $store = $quote->store;

        return Pdf::loadView('pdf.quote-document', [
            'currency' => config('subscriptions.default_currency', 'XOF'),
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
            'quote' => $quote,
            'author' => $quote->user ? trim($quote->user->first_name . ' ' . $quote->user->last_name) : null,
        ])
            ->setPaper('a4')
            ->setOption('isFontSubsettingEnabled', true)
            ->output();
    }

    /** L'envoi WhatsApp s'applique-t-il à ce devis (boutique, WAHA, téléphone du client) ? */
    public function whatsappAvailable(Quote $quote): bool
    {
        return $this->chatIdFor($quote) !== null;
    }

    /**
     * Envoi immédiat du devis ; lève une exception si WAHA refuse ou ne répond pas.
     *
     * @throws \Throwable
     */
    public function sendWhatsapp(Quote $quote): void
    {
        $chatId = $this->chatIdFor($quote) ?? throw new \RuntimeException('Envoi WhatsApp indisponible pour ce devis.');

        $currency = config('subscriptions.default_currency', 'XOF');
        $money = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' ' . $currency;
        $name = $quote->customer?->name;
        $caption = 'Bonjour' . ($name ? ' ' . $name : '') . ",\n\n"
            . match ($quote->status) {
                'accepted' => "Voici votre devis {$quote->quote_number} accepté chez *{$quote->store->name}*",
                default => "Voici votre devis {$quote->quote_number} de *{$quote->store->name}*",
            }
            . " : *{$money($quote->total_amount)}*."
            . ($quote->valid_until && $quote->isEditable() ? "\nOffre valable jusqu'au {$quote->valid_until->format('d/m/Y')}." : '')
            . "\n\nLe devis est joint à ce message.";

        $this->waha->sendFile($chatId, $this->pdf($quote), $this->filename($quote), 'application/pdf', $caption);
    }

    private function chatIdFor(Quote $quote): ?string
    {
        $quote->loadMissing(['store', 'customer']);

        if (!$quote->store?->whatsapp_invoices_enabled || !$this->waha->isConfigured()) {
            return null;
        }

        return $this->waha->chatId($quote->customer?->phone);
    }
}
