<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * API pure JSON : jamais de redirection vers une route "login" nommée.
     */
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }
}
