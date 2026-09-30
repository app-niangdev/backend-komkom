<?php

namespace App\Services;

use App\Support\WhatsappNumber;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client minimal de WAHA (WhatsApp HTTP API) : envoi d'un fichier à un numéro.
 * Configuration : `services.waha` (WAHA_BASE_URL, WAHA_API_KEY, WAHA_SESSION).
 */
class WahaService
{
    public function isConfigured(): bool
    {
        return config('services.waha.base_url') !== '' && config('services.waha.api_key') !== '';
    }

    /** Identifiant de discussion WAHA (`221770906538@c.us`), ou null si le numéro est inexploitable. */
    public function chatId(?string $phone): ?string
    {
        $number = WhatsappNumber::normalize($phone);

        return $number ? $number . '@c.us' : null;
    }

    /**
     * Envoie un fichier (contenu binaire, transmis en base64) avec une légende.
     *
     * @throws RuntimeException|RequestException
     */
    public function sendFile(string $chatId, string $content, string $filename, string $mimetype, ?string $caption = null): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('WAHA n\'est pas configuré (WAHA_BASE_URL / WAHA_API_KEY).');
        }

        return Http::baseUrl(config('services.waha.base_url'))
            ->withHeaders(['X-Api-Key' => config('services.waha.api_key')])
            ->acceptJson()
            ->timeout(config('services.waha.timeout'))
            ->post('/api/sendFile', [
                'session' => config('services.waha.session'),
                'chatId' => $chatId,
                'file' => [
                    'mimetype' => $mimetype,
                    'filename' => $filename,
                    'data' => base64_encode($content),
                ],
                'caption' => $caption,
                'mentions' => null,
                'reply_to' => null,
            ])
            ->throw()
            ->json() ?? [];
    }
}
