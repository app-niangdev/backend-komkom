<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UnitOfMeasure extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'product_id',
        'name',
        'price',
        'conversion_factor', // Nouveau: facteur de conversion vers l'unité de base
        'is_base_unit', // Nouveau: si c'est l'unité de base
    ];

    protected $casts = [
        'is_base_unit' => 'boolean',
        'conversion_factor' => 'decimal:4',
    ];

    /**
     * Relation avec le produit
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Scope pour l'unité de base
     */
    public function scopeBaseUnit($query)
    {
        return $query->where('is_base_unit', true);
    }

    /**
     * Scope pour les unités de vente
     */
    public function scopeSellingUnits($query)
    {
        return $query->where('is_base_unit', false);
    }

    /**
     * Convertit une quantité de cette unité vers l'unité de base
     */
    public function convertToBaseUnit($quantity)
    {
        return $quantity * $this->conversion_factor;
    }

    /**
     * Convertit une quantité de l'unité de base vers cette unité
     */
    public function convertFromBaseUnit($quantity)
    {
        return $quantity / $this->conversion_factor;
    }

    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at'
    ];
}
