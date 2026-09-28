<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Abonnement d'une boutique sur une période [starts_at ; ends_at] (bornes incluses).
 * Une boutique peut en enchaîner plusieurs (renouvellements, prépaiement d'une période future).
 */
class Subscription extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'store_id',
        'plan',
        'amount',
        'currency',
        'starts_at',
        'ends_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'starts_at' => 'date:Y-m-d',
        'ends_at' => 'date:Y-m-d',
        'amount' => 'decimal:2',
    ];

    protected $hidden = [
        'deleted_at',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
