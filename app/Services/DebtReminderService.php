<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerReminder;
use App\Models\Invoice;
use App\Models\Store;
use App\Models\User;
use App\Support\WhatsappNumber;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Relance WhatsApp d'un client débiteur : rappel du reste dû et de ses factures non soldées.
 * Mêmes conditions que l'envoi des factures (boutique activée par l'administrateur, WAHA configuré,
 * numéro exploitable), plus un délai minimal entre deux relances d'un même client : le numéro
 * d'envoi est commun à toutes les boutiques, il ne doit pas être signalé comme indésirable.
 */
class DebtReminderService
{
    /** Au-delà, les factures les plus récentes sont résumées en une ligne. */
    private const MAX_LISTED_INVOICES = 5;

    private const BLOCKER_MESSAGES = [
        'disabled' => 'Les envois WhatsApp ne sont pas activés pour cette boutique.',
        'no_phone' => 'Numéro de téléphone absent ou invalide.',
        'no_debt' => 'Ce client ne doit plus rien.',
        'recent' => 'Client déjà relancé récemment.',
        'busy' => 'Une relance est déjà en cours pour ce client.',
    ];

    public function __construct(protected WahaService $waha)
    {
    }

    public function cooldownHours(): int
    {
        return max(0, (int) config('services.waha.reminder_cooldown_hours'));
    }

    /** Les relances sont-elles possibles dans cette boutique ? */
    public function enabledFor(?Store $store): bool
    {
        return (bool) $store?->whatsapp_invoices_enabled && $this->waha->isConfigured();
    }

    /** Ce qui empêche de relancer le client (`disabled`, `no_phone`, `no_debt`, `recent`), ou null. */
    public function blocker(?Store $store, ?string $phone, float $balance, ?CarbonInterface $lastSentAt): ?string
    {
        return match (true) {
            !$this->enabledFor($store) => 'disabled',
            $balance <= 0 => 'no_debt',
            WhatsappNumber::normalize($phone) === null => 'no_phone',
            $lastSentAt && $lastSentAt->gt(now()->subHours($this->cooldownHours())) => 'recent',
            default => null,
        };
    }

    public function blockerMessage(string $blocker): string
    {
        return $blocker === 'recent'
            ? 'Client déjà relancé il y a moins de ' . $this->cooldownHours() . ' h.'
            : self::BLOCKER_MESSAGES[$blocker];
    }

    /**
     * Envoie la relance. Le reste dû est recalculé ici : un client qui a payé entre-temps est ignoré.
     *
     * @return array{status: 'sent'|'skipped'|'failed', reason: ?string, message: string, fatal: bool, last_at: ?string}
     */
    public function remind(Customer $customer, User $user): array
    {
        // Double clic ou deux utilisateurs en même temps : un seul message part
        $lock = Cache::lock('customer-reminder:' . $customer->id, 60);
        if (!$lock->get()) {
            return $this->result('skipped', self::BLOCKER_MESSAGES['busy'], 'busy');
        }

        try {
            return $this->send($customer, $user);
        } finally {
            $lock->release();
        }
    }

    private function send(Customer $customer, User $user): array
    {
        $customer->loadMissing('store');
        $invoices = Invoice::where('customer_id', $customer->id)
            ->where('is_cancelled', false)
            ->where('balance', '>', 0)
            ->orderBy('created_at')
            ->get(['id', 'invoice_number', 'balance', 'created_at']);
        $balance = (int) $invoices->sum('balance');

        $lastSentAt = CustomerReminder::where('customer_id', $customer->id)->where('status', 'sent')->max('created_at');
        $blocker = $this->blocker($customer->store, $customer->phone, $balance, $lastSentAt ? Carbon::parse($lastSentAt) : null);
        if ($blocker) {
            return $this->result('skipped', $this->blockerMessage($blocker), $blocker);
        }

        $attempt = [
            'customer_id' => $customer->id,
            'store_id' => $customer->store_id,
            'user_id' => $user->id,
            'amount' => $balance,
            'invoices_count' => $invoices->count(),
            'phone' => WhatsappNumber::normalize($customer->phone),
        ];

        try {
            $this->waha->sendText($this->waha->chatId($customer->phone), $this->message($customer, $invoices, $balance));
        } catch (Throwable $e) {
            Log::warning('Relance WhatsApp impossible', ['customer_id' => $customer->id, 'error' => $e->getMessage()]);
            CustomerReminder::create($attempt + ['status' => 'failed', 'error' => Str::limit($e->getMessage(), 490)]);
            [$message, $fatal] = $this->failure($e);

            return $this->result('failed', $message, 'send_failed', $fatal);
        }

        $reminder = CustomerReminder::create($attempt + ['status' => 'sent']);

        return $this->result('sent', 'Relance envoyée à ' . $customer->name . '.', null, false, $reminder->created_at->toIso8601String());
    }

    /**
     * Message affiché à l'utilisateur, et si la panne touche tout le service (inutile de
     * poursuivre un lot) ou seulement ce destinataire.
     *
     * @return array{0: string, 1: bool}
     */
    private function failure(Throwable $e): array
    {
        if ($e instanceof ConnectionException) {
            return ['Le service WhatsApp ne répond pas. Réessayez dans quelques minutes.', true];
        }

        $status = $e instanceof RequestException ? $e->response->status() : null;

        return match (true) {
            $status === 401, $status === 403 => ['Le service WhatsApp refuse l\'accès. Contactez l\'administrateur.', true],
            $status === 404, $status === 422 => ['La session WhatsApp n\'est pas connectée. Contactez l\'administrateur.', true],
            default => ['Le message n\'a pas pu être envoyé à ce numéro.', false],
        };
    }

    private function message(Customer $customer, Collection $invoices, int $balance): string
    {
        $currency = config('subscriptions.default_currency', 'XOF');
        $money = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' ' . $currency;
        $store = $customer->store;

        $lines = $invoices->take(self::MAX_LISTED_INVOICES)->map(fn (Invoice $i) => sprintf(
            '• Facture %s du %s : reste %s',
            $i->invoice_number,
            $i->created_at->format('d/m/Y'),
            $money($i->balance)
        ));
        $others = $invoices->count() - $lines->count();
        if ($others > 0) {
            $lines->push('• et ' . $others . ($others > 1 ? ' autres factures' : ' autre facture'));
        }

        return "Bonjour {$customer->name},\n\n"
            . "Sauf erreur de notre part, il vous reste *{$money($balance)}* à régler chez *{$store->name}* :\n"
            . $lines->implode("\n")
            . "\n\nMerci de passer nous voir"
            . ($store->phone_one ? " ou de nous contacter au {$store->phone_one}" : '') . ".\n"
            . 'Si vous avez déjà réglé, merci de ne pas tenir compte de ce message.';
    }

    private function result(string $status, string $message, ?string $reason = null, bool $fatal = false, ?string $lastAt = null): array
    {
        return ['status' => $status, 'reason' => $reason, 'message' => $message, 'fatal' => $fatal, 'last_at' => $lastAt];
    }
}
