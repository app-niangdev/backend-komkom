<?php

namespace App\Services;

use App\Exceptions\RefreshTokenReuseException;
use App\Models\RefreshToken;
use App\Models\User;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use UnexpectedValueException;

class JwtService
{
    public function issueAccessToken(User $user): string
    {
        $now = time();

        $payload = [
            'sub' => $user->id,
            'iat' => $now,
            'exp' => $now + (config('jwt.access_ttl') * 60),
            'jti' => (string) Str::uuid(),
        ];

        return JWT::encode($payload, config('jwt.secret'), config('jwt.algo'));
    }

    public function decodeAccessToken(string $token): ?object
    {
        try {
            return JWT::decode($token, new Key(config('jwt.secret'), config('jwt.algo')));
        } catch (ExpiredException|SignatureInvalidException|UnexpectedValueException|\Exception $e) {
            return null;
        }
    }

    public function issueRefreshToken(User $user, bool $rememberMe, ?string $familyId = null, ?Request $request = null): array
    {
        $rawToken = Str::random(64);
        $ttlMinutes = $rememberMe ? config('jwt.refresh_ttl_remember') : config('jwt.refresh_ttl');

        $refreshToken = RefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $rawToken),
            'family_id' => $familyId ?? (string) Str::uuid(),
            'expires_at' => now()->addMinutes($ttlMinutes),
            'user_agent' => $request?->userAgent(),
            'ip_address' => $request?->ip(),
        ]);

        return ['raw' => $rawToken, 'model' => $refreshToken];
    }

    public function rotateRefreshToken(string $rawToken, bool $rememberMe, ?Request $request = null): array
    {
        $hash = hash('sha256', $rawToken);
        $existing = RefreshToken::where('token_hash', $hash)->first();

        if (! $existing) {
            throw new RefreshTokenReuseException('Refresh token not found.');
        }

        if ($existing->isRevoked() || $existing->isExpired()) {
            RefreshToken::where('family_id', $existing->family_id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            throw new RefreshTokenReuseException('Refresh token reuse detected.');
        }

        $user = $existing->user;

        $new = $this->issueRefreshToken($user, $rememberMe, $existing->family_id, $request);

        $existing->update([
            'revoked_at' => now(),
            'replaced_by_id' => $new['model']->id,
        ]);

        return ['raw' => $new['raw'], 'model' => $new['model'], 'user' => $user];
    }

    public function revokeRefreshToken(string $rawToken): void
    {
        RefreshToken::where('token_hash', hash('sha256', $rawToken))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function revokeAllForUser(int $userId): void
    {
        RefreshToken::where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }
}
