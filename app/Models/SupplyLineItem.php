<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplyLineItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'supply_id',
        'product_id',
        'quantity', // Toujours en unité de base
        'base_unit_quantity', // Quantité en unité de base (redundant avec quantity mais clair)
        'unit_of_measure_id', // Référence à l'unité de base utilisée
        'purchase_price'
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'purchase_price' => 'decimal:2',
    ];

    /**
     * Relation avec l'approvisionnement
     */
    public function supply()
    {
        return $this->belongsTo(Supply::class);
    }

    public function serialNumbers()
    {
        return $this->hasMany(SerialNumber::class);
    }

    /**
     * Relation avec le produit
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Relation avec l'unité de mesure (toujours l'unité de base)
     */
    public function unitOfMeasure()
    {
        return $this->belongsTo(UnitOfMeasure::class);
    }

    /**
     * Accessor pour le total de la ligne
     */
    public function getTotalAttribute()
    {
        return $this->quantity * $this->purchase_price;
    }

    /**
     * Boot method pour s'assurer qu'on utilise l'unité de base
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($lineItem) {
            // S'assurer qu'on utilise l'unité de mesure de base
            $baseUnit = $lineItem->product->baseUnitOfMeasure;
            $lineItem->unit_of_measure_id = $baseUnit->id;
            $lineItem->base_unit_quantity = $lineItem->quantity;
        });
    }

    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at'
    ];
}
