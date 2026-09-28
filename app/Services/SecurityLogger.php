<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Journal dédié aux événements de connexion, sur un canal séparé du reste
 * de l'application. Aucun secret (mot de passe, code OTP, jeton) n'y est
 * jamais écrit.
 */
class SecurityLogger
{
    public const LOGIN_FAILED = 'login_failed';
    public const LOGIN_SUCCESS = 'login_success';
    public const LOGIN_COMPLETED = 'login_completed';
    public const IP_BLOCKED = 'ip_blocked';
    public const IP_BLOCKED_REQUEST = 'ip_blocked_request';
    public const IP_BLOCK_EXPIRED = 'ip_block_expired';
    public const OTP_SENT = 'otp_sent';
    public const OTP_VERIFIED = 'otp_verified';
    public const OTP_FAILED = 'otp_failed';
    public const PASSWORD_CHANGED = 'password_changed';

    public function log(string $event, ?int $userId = null, array $context = []): void
    {
        Log::channel('security')->info($event, array_merge([
            'user_id' => $userId,
        ], $context));
    }
}
