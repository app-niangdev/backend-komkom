<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class LoginSecurity extends Model
{
    protected $table = 'login_security';

    protected $fillable = [
        'ip_address',
        'failed_attempts',
        'block_level',
        'blocked_until',
        'was_blocked',
        'last_failed_at',
        'last_success_at',
    ];

    protected function casts(): array
    {
        return [
            'blocked_until' => 'datetime',
            'was_blocked' => 'boolean',
            'last_failed_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }

    public function isBlocked(): bool
    {
        return $this->blocked_until !== null && $this->blocked_until->isFuture();
    }

    public function retryAfter(): int
    {
        if (!$this->isBlocked()) {
            return 0;
        }

        return max(0, Carbon::now()->diffInSeconds($this->blocked_until, false));
    }
}
