<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'store_id',
        'seller_id',
        'sale_number',
        'gross_amount',
        'discount',
        'total_amount',
        'status',
        'status_payment',
        'customer_id',
    ];

    /**
     * Relation avec le magasin
     */
    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    protected $hidden = [
        'updated_at',
        'deleted_at'
    ];

    /**
     * Relation avec le vendeur
     */
    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    /**
     * Lignes de vente associées
     */
    public function lineItems()
    {
        return $this->hasMany(SaleLineItem::class);
    }

    /**
     * Relation avec le client
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Relation avec la facture
     */
    public function invoice()
    {
        return $this->hasOne(Invoice::class);
    }

    /**
     * Relation avec les lignes de vente (alias pour saleLineItems)
     */
    public function saleLineItems()
    {
        return $this->hasMany(SaleLineItem::class);
    }
}

