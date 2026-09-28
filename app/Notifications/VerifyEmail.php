<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class VerifyEmail extends Notification
{
    /**
     * The callback that should be used to create the verify email URL.
     *
     * @var \Closure|null
     */
    public static $createUrlCallback;

    /**
     * Get the notification's channels.
     *
     * @param  mixed  $notifiable
     * @return array|string
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Build the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        $verificationUrl = $this->verificationUrl($notifiable);

        return (new MailMessage)
            ->subject('Vérification de votre adresse e-mail')
            ->line('Veuillez cliquer sur le bouton ci-dessous pour vérifier votre adresse e-mail.')
            ->action('Vérifier l\'adresse e-mail', $verificationUrl)
            ->line('Si vous n\'avez pas créé de compte, aucune action supplémentaire n\'est requise.');
    }

    /**
     * Get the verification URL for the given notifiable.
     *
     * @param  mixed  $notifiable
     * @return string
     */
    protected function verificationUrl($notifiable)
    {
        if (static::$createUrlCallback) {
            return call_user_func(static::$createUrlCallback, $notifiable);
        }

        // Récupérer l'URL du frontend
        $frontendUrl = config('app.frontend_url');

        Log::info('---------------VerifyEmail');
        Log::info($frontendUrl);
        // S'assurer que l'URL du frontend se termine par un slash
        if (!str_ends_with($frontendUrl, '/')) {
            $frontendUrl .= '/';
        }

        // Générer les paramètres manuellement
        $id = $notifiable->getKey();
        $hash = sha1($notifiable->getEmailForVerification());
        $expires = Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60))->getTimestamp();

        // Créer la chaîne de signature
        $signatureString = "id={$id}&hash={$hash}&expires={$expires}";
        $signature = hash_hmac('sha256', $signatureString, config('app.key'));

        // Construire l'URL complète avec le frontend
        $verificationUrl = $frontendUrl . "email/verify/{$id}/{$hash}?expires={$expires}&signature={$signature}";

        Log::info('URL de vérification: ' . $verificationUrl);

        return $verificationUrl;
    }

}
