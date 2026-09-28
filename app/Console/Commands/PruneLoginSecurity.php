<?php

namespace App\Console\Commands;

use App\Models\LoginChallenge;
use App\Services\LoginSecurityService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PruneLoginSecurity extends Command
{
    protected $signature = 'login-security:prune';

    protected $description = 'Purge les lignes login_security et login_challenges devenues inutiles';

    public function handle(LoginSecurityService $securite): int
    {
        $joursSecurite = (int) config('login_security.prune.security_days', 30);
        $joursChallenges = (int) config('login_security.prune.challenges_days', 7);

        $supprimesSecurite = $securite->purger($joursSecurite);

        $supprimesChallenges = LoginChallenge::query()
            ->where('created_at', '<', Carbon::now()->subDays($joursChallenges))
            ->where(function ($q) {
                $q->whereNotNull('consumed_at')
                    ->orWhere('expires_at', '<', Carbon::now());
            })
            ->delete();

        $this->info("login_security : {$supprimesSecurite} ligne(s) supprimée(s).");
        $this->info("login_challenges : {$supprimesChallenges} ligne(s) supprimée(s).");

        return self::SUCCESS;
    }
}
