<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Menu extends Model
{
    protected $fillable = [
        'code',
        'title',
        'type',
        'classes',
        'url',
        'icon',
        'breadcrumbs',
        'position',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'breadcrumbs' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'menu_role');
    }
}
