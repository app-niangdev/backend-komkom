<?php

namespace App\Services;

use App\Models\LoginSecurity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Blocage progressif des adresses IP après des échecs de connexion répétés.
 *
 * Toute écriture passe par une transaction avec verrou de ligne : des essais
 * concurrents ne doivent compter qu'une fois chacun, et deux échecs
 * simultanés ne doivent pas poser deux blocages qui s'écraseraient l'un
 * l'autre.
 */
class LoginSecurityService
{
    public function __construct(
        private readonly SecurityLogger $journal,
    ) {
    }

    public function etat(string $ip): ?LoginSecurity
    {
        return LoginSecurity::query()->where('ip_address', $ip)->first();
    }

    public function enregistrerEchec(string $ip): LoginSecurity
    {
        return DB::transaction(function () use ($ip) {
            $etat = $this->verrouillerOuCreer($ip);

            // Garde-fou : le controleur a deja refuse les IP bloquees, mais
            // deux requetes parties ensemble peuvent franchir ce controle
            // avant que la premiere n'ait pose le blocage.
            if ($etat->isBlocked()) {
                return $etat;
            }

            $etat->failed_attempts += 1;
            $etat->last_failed_at = Carbon::now();

            $seuil = (int) config('login_security.max_attempts', 5);

            if ($etat->failed_attempts >= $seuil) {
                $etat->block_level += 1;
                $duree = $this->dureeBlocage($etat->block_level);
                $etat->blocked_until = Carbon::now()->addSeconds($duree);
                $etat->was_blocked = true;

                $etat->save();

                $this->journal->log(SecurityLogger::IP_BLOCKED, null, [
                    'block_level' => $etat->block_level,
                    'failed_attempts' => $etat->failed_attempts,
                    'blocked_until' => $etat->blocked_until->toIso8601String(),
                    'duration' => $duree,
                ]);

                return $etat;
            }

            $etat->save();

            $this->journal->log(SecurityLogger::LOGIN_FAILED, null, [
                'failed_attempts' => $etat->failed_attempts,
                'attempts_left' => $seuil - $etat->failed_attempts,
            ]);

            return $etat;
        });
    }

    public function reinitialiser(string $ip): void
    {
        DB::transaction(function () use ($ip) {
            $etat = $this->verrouillerOuCreer($ip);

            $etat->fill([
                'failed_attempts' => 0,
                'block_level' => 0,
                'blocked_until' => null,
                'was_blocked' => false,
                'last_success_at' => Carbon::now(),
            ])->save();
        });
    }

    public function aEteBloquee(string $ip): bool
    {
        $etat = $this->etat($ip);

        return $etat !== null && $etat->was_blocked;
    }

    public function purger(int $joursInactivite): int
    {
        return LoginSecurity::query()
            ->where('updated_at', '<', Carbon::now()->subDays($joursInactivite))
            ->where(function ($q) {
                $q->whereNull('blocked_until')
                    ->orWhere('blocked_until', '<', Carbon::now());
            })
            ->delete();
    }

    /**
     * Duree du blocage pour un palier donne : base x 2^(niveau - 1), plafonnee.
     *
     * L'exposant est borne a 30 pour éviter un débordement d'entier avant
     * l'élévation.
     */
    private function dureeBlocage(int $niveau): int
    {
        $base = (int) config('login_security.base_block_seconds', 60);
        $plafond = (int) config('login_security.max_block_seconds', 86400);

        $exposant = max(0, min($niveau - 1, 30));
        $duree = $base * (2 ** $exposant);

        return (int) min($duree, $plafond);
    }

    private function verrouillerOuCreer(string $ip): LoginSecurity
    {
        $etat = LoginSecurity::query()
            ->where('ip_address', $ip)
            ->lockForUpdate()
            ->first();

        if ($etat) {
            return $etat;
        }

        try {
            return LoginSecurity::query()->create([
                'ip_address' => $ip,
                'failed_attempts' => 0,
                'block_level' => 0,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return LoginSecurity::query()
                ->where('ip_address', $ip)
                ->lockForUpdate()
                ->firstOrFail();
        }
    }
}
