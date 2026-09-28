<?php

namespace App\Http\Middleware;

use App\Services\LoginSecurityService;
use App\Services\SecurityLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloque une IP sous blocage avant qu'elle n'atteigne le contrôleur de
 * connexion : aucun mot de passe n'est vérifié, aucun compteur n'est
 * touché tant que le blocage court.
 */
class BlockSuspiciousIp
{
    public function __construct(
        private readonly LoginSecurityService $securite,
        private readonly SecurityLogger $journal,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $etat = $this->securite->etat($request->ip());

        if ($etat === null || !$etat->isBlocked()) {
            return $next($request);
        }

        $retryAfter = $etat->retryAfter();

        $this->journal->log(SecurityLogger::IP_BLOCKED_REQUEST, null, [
            'path' => $request->path(),
            'method' => $request->method(),
            'retry_after' => $retryAfter,
        ]);

        return response()->json([
            'status' => false,
            'code' => 'IP_BLOCKED',
            'message' => 'Trop de tentatives de connexion. Veuillez patienter.',
            'blocked_until' => $etat->blocked_until->toIso8601String(),
            'retry_after' => $retryAfter,
        ], 429)->withHeaders([
            'Retry-After' => (string) $retryAfter,
        ]);
    }
}
