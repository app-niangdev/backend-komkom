<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LoginOtpNotification extends Notification
{
    public function __construct(
        public string $code,
        public int $ttlMinutes,
    ) {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Code de vérification de connexion')
            ->greeting('Bonjour ' . $notifiable->first_name . ',')
            ->line('Une connexion à votre compte nécessite une vérification supplémentaire.')
            ->line('Votre code de vérification est :')
            ->line(new \Illuminate\Support\HtmlString('<h2 style="letter-spacing:4px;text-align:center;">' . $this->code . '</h2>'))
            ->line("Ce code expire dans {$this->ttlMinutes} minute(s).")
            ->line("Si vous n'êtes pas à l'origine de cette tentative de connexion, changez votre mot de passe dès que possible.");
    }
}
