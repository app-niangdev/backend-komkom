<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SaleLineItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'sale_id',
        'product_id',
        'unit_of_measure_id',
        'unit_name',
        'quantity',
        'base_quantity',
        'unit_price',
        'subtotal',
        'unit_price_at_sale',
        'sale_amount',
    ];

    /**
     * Relation avec la vente
     */
    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * Relation avec le produit
     */
    public function unitOfMeasure()
    {
        return $this->belongsTo(UnitOfMeasure::class);
    }

    /** Quantité sortie du stock, en unité de base (les anciennes lignes n'ont que `quantity`). */
    public function stockQuantity(): float
    {
        return (float) ($this->base_quantity ?? $this->quantity);
    }

    /** Produit vendu, même supprimé depuis : l'historique reste lisible. */
    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * Relation avec le numéro de série (si applicable)
     */
    public function serialNumbers()
    {
        return $this->hasMany(SerialNumber::class, 'sale_line_item_id');
    }

    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at'
    ];
}
