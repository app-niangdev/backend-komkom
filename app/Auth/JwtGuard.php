<?php

namespace App\Auth;

use App\Services\AuthCookieService;
use App\Services\JwtService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;

class JwtGuard implements Guard
{
    protected ?Authenticatable $user = null;

    protected bool $resolved = false;

    public function __construct(
        protected UserProvider $provider,
        protected Request $request,
        protected JwtService $jwtService,
        protected AuthCookieService $cookieService,
    ) {
    }

    public function check(): bool
    {
        return ! is_null($this->user());
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function user(): ?Authenticatable
    {
        if ($this->resolved) {
            return $this->user;
        }

        $this->resolved = true;

        $parsed = $this->cookieService->parse($this->request->cookie(config('jwt.cookie_name')));

        if (! $parsed) {
            return $this->user = null;
        }

        $decoded = $this->jwtService->decodeAccessToken($parsed['at']);

        if (! $decoded || ! isset($decoded->sub)) {
            return $this->user = null;
        }

        $this->user = $this->provider->retrieveById($decoded->sub);

        return $this->user;
    }

    public function id(): int|string|null
    {
        return $this->user()?->getAuthIdentifier();
    }

    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function hasUser(): bool
    {
        return ! is_null($this->user);
    }

    public function setUser(Authenticatable $user): static
    {
        $this->user = $user;
        $this->resolved = true;

        return $this;
    }
}
