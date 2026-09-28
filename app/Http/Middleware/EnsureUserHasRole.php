<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Restreint la route aux rôles donnés (ex. : ->middleware('role:Admin')).
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $roleName = $request->user()?->role?->name;

        if (! $roleName || ! in_array($roleName, $roles, true)) {
            return response()->json([
                'status' => false,
                'message' => 'Accès non autorisé pour votre rôle.',
            ], 403);
        }

        return $next($request);
    }
}
