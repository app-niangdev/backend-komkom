<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    public function __construct(public string $token) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Réinitialisation de votre mot de passe - KomKom')
            ->view('emails.reset-password', [
                'user' => $notifiable,
                'resetUrl' => self::resetUrl($this->token, $notifiable->getEmailForPasswordReset()),
                'expireMinutes' => (int) config('auth.passwords.users.expire', 60),
            ]);
    }

    /** Page Angular de saisie du nouveau mot de passe (route /auth/reset-password). */
    public static function resetUrl(string $token, ?string $email = null): string
    {
        $query = http_build_query(array_filter(['token' => $token, 'email' => $email]));

        return config('app.frontend_url') . '/auth/reset-password?' . $query;
    }
}
