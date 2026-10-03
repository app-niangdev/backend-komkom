<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Ligne de devis : copie figée de la désignation, de l'unité et du prix au moment de la saisie. */
class QuoteLineItem extends Model
{
    protected $fillable = [
        'quote_id',
        'product_id',
        'unit_of_measure_id',
        'designation',
        'unit_name',
        'quantity',
        'unit_price',
        'subtotal',
        'position',
    ];

    public function quote()
    {
        return $this->belongsTo(Quote::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
