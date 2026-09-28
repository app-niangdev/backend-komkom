<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\Cookie;

class AuthCookieService
{
    public function make(string $accessToken, string $refreshToken, int $ttlMinutes): Cookie
    {
        $value = json_encode(['at' => $accessToken, 'rt' => $refreshToken]);

        return Cookie::create(config('jwt.cookie_name'))
            ->withValue($value)
            ->withExpires(now()->addMinutes($ttlMinutes))
            ->withPath('/')
            ->withDomain(config('jwt.cookie_domain'))
            ->withSecure(config('jwt.cookie_secure'))
            ->withHttpOnly(true)
            ->withSameSite(config('jwt.cookie_samesite'));
    }

    public function forget(): Cookie
    {
        return Cookie::create(config('jwt.cookie_name'))
            ->withValue('')
            ->withExpires(now()->subYear())
            ->withPath('/')
            ->withDomain(config('jwt.cookie_domain'))
            ->withSecure(config('jwt.cookie_secure'))
            ->withHttpOnly(true)
            ->withSameSite(config('jwt.cookie_samesite'));
    }

    public function parse(?string $rawCookieValue): ?array
    {
        if (! $rawCookieValue) {
            return null;
        }

        $decoded = json_decode($rawCookieValue, true);

        if (! is_array($decoded) || ! isset($decoded['at'], $decoded['rt'])) {
            return null;
        }

        return $decoded;
    }
}
