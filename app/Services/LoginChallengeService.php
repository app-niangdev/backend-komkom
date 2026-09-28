<?php

namespace App\Services;

use App\Models\LoginChallenge;
use App\Models\User;
use App\Notifications\LoginOtpNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LoginChallengeService
{
    public const RAISON_BLOCAGE = 'ip_unblocked';

    public function __construct(
        private readonly SecurityLogger $journal,
    ) {
    }

    public function ouvrir(User $user, string $ip, string $raison): LoginChallenge
    {
        $code = (string) random_int(100000, 999999);
        $ttl = (int) config('login_security.otp.ttl', 300);

        $challenge = LoginChallenge::query()->create([
            'user_id' => $user->id,
            'ip_address' => $ip,
            'challenge_token' => Str::random(48),
            'code_hash' => Hash::make($code),
            'reason' => $raison,
            'last_sent_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addSeconds($ttl),
        ]);

        $user->notify(new LoginOtpNotification($code, (int) ceil($ttl / 60)));

        $this->journal->log(SecurityLogger::OTP_SENT, $user->id, [
            'reason' => $raison,
        ]);

        return $challenge;
    }

    /**
     * @return array{ok: bool, code?: string, message?: string, user?: User}
     */
    public function verifier(string $challengeToken, string $code): array
    {
        $challenge = LoginChallenge::query()
            ->where('challenge_token', $challengeToken)
            ->first();

        if ($challenge === null || $challenge->isConsumed()) {
            return ['ok' => false, 'code' => 'CHALLENGE_INVALID', 'message' => 'Code de vérification invalide.'];
        }

        if ($challenge->isExpired()) {
            return ['ok' => false, 'code' => 'CHALLENGE_EXPIRED', 'message' => 'Ce code a expiré. Veuillez en demander un nouveau.'];
        }

        $maxAttempts = (int) config('login_security.otp.max_attempts', 5);

        if ($challenge->attempts >= $maxAttempts) {
            return ['ok' => false, 'code' => 'CHALLENGE_LOCKED', 'message' => 'Trop de tentatives. Veuillez demander un nouveau code.'];
        }

        if (!Hash::check($code, $challenge->code_hash)) {
            $challenge->increment('attempts');

            $this->journal->log(SecurityLogger::OTP_FAILED, $challenge->user_id);

            return ['ok' => false, 'code' => 'CODE_INVALID', 'message' => 'Code de vérification incorrect.'];
        }

        $challenge->update(['consumed_at' => Carbon::now()]);

        $this->journal->log(SecurityLogger::OTP_VERIFIED, $challenge->user_id);

        return ['ok' => true, 'user' => $challenge->user];
    }

    /**
     * @return array{ok: bool, code?: string, message?: string}
     */
    public function renvoyer(string $challengeToken): array
    {
        $challenge = LoginChallenge::query()
            ->where('challenge_token', $challengeToken)
            ->first();

        if ($challenge === null || $challenge->isConsumed() || $challenge->isExpired()) {
            return ['ok' => false, 'code' => 'CHALLENGE_INVALID', 'message' => 'Session de vérification expirée. Veuillez vous reconnecter.'];
        }

        $maxResend = (int) config('login_security.otp.max_resend', 3);

        if ($challenge->resend_count >= $maxResend) {
            return ['ok' => false, 'code' => 'RESEND_LIMIT', 'message' => 'Nombre maximal de renvois atteint.'];
        }

        $cooldown = (int) config('login_security.otp.resend_cooldown', 60);

        if ($challenge->last_sent_at !== null && Carbon::now()->diffInSeconds($challenge->last_sent_at) < $cooldown) {
            return ['ok' => false, 'code' => 'RESEND_COOLDOWN', 'message' => 'Veuillez patienter avant de redemander un code.'];
        }

        $code = (string) random_int(100000, 999999);
        $ttl = (int) config('login_security.otp.ttl', 300);

        $challenge->update([
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'resend_count' => $challenge->resend_count + 1,
            'last_sent_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addSeconds($ttl),
        ]);

        $challenge->user->notify(new LoginOtpNotification($code, (int) ceil($ttl / 60)));

        $this->journal->log(SecurityLogger::OTP_SENT, $challenge->user_id, [
            'resend' => true,
        ]);

        return ['ok' => true];
    }
}
