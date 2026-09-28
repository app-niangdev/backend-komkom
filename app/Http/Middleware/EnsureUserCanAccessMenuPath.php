<?php

namespace App\Http\Middleware;

use App\Services\MenuService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserCanAccessMenuPath
{
    public function __construct(protected MenuService $menus)
    {
    }

    /**
     * Contrôle d'accès basé sur les menus en base (en-tête X-App-Path = chemin Angular).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $path = $request->header('X-App-Path');

        if (! $user || ! $path) {
            return $next($request);
        }

        if (! $this->menus->userCanAccessPath($user, $path)) {
            return response()->json([
                'status' => false,
                'message' => 'Accès non autorisé pour votre rôle.',
            ], 403);
        }

        return $next($request);
    }
}
