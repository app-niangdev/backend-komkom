<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Devis d'un client : brouillon → envoyé (encore modifiable) → accepté ou refusé (figé, duplicable).
 * N'a aucun effet sur le stock, les produits ou les ventes.
 */
class Quote extends Model
{
    use SoftDeletes;

    public const STATUSES = ['draft', 'sent', 'accepted', 'refused'];

    protected $fillable = [
        'store_id',
        'customer_id',
        'user_id',
        'source_quote_id',
        'quote_number',
        'status',
        'valid_until',
        'notes',
        'gross_amount',
        'discount',
        'total_amount',
        'sent_at',
        'decided_at',
    ];

    protected $casts = [
        'valid_until' => 'date',
        'sent_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    /** Contenu modifiable tant que le client n'a pas répondu. */
    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'sent'], true);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function source()
    {
        return $this->belongsTo(Quote::class, 'source_quote_id')->withTrashed();
    }

    public function items()
    {
        return $this->hasMany(QuoteLineItem::class)->orderBy('position')->orderBy('id');
    }
}
