<?php
// App\Notifications\ResetPasswordNotification.php
namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Lang;

class ResetPasswordNotification extends Notification
{
    public function __construct(public string $token) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $resetUrl = env('FRONTEND_URL')
            . '/reset-password?token='
            . $this->token
            . '&email='
            . urlencode($notifiable->getEmailForPasswordReset());

        return (new MailMessage)
            ->subject('Réinitialisation de votre mot de passe - KomKom')
            ->view('emails.reset-password', [
                'user'     => $notifiable,
                'resetUrl' => $resetUrl,
            ]);
    }
}
