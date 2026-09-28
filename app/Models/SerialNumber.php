<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SerialNumber extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'product_id',
        'supply_line_item_id',
        'sale_line_item_id',
        'serial_number',
        'is_sold',
    ];

    /**
     * Relation avec le produit
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Relation avec la ligne d'approvisionnement
     */
    public function supplyLineItem()
    {
        return $this->belongsTo(SupplyLineItem::class);
    }

    /**
     * Relation avec la ligne de vente
     */
    public function saleLineItem()
    {
        return $this->belongsTo(SaleLineItem::class);
    }

    /**
     * Scope pour les numéros de série disponibles.
     * Un numéro de série est disponible s'il n'est pas vendu,
     * n'est pas associé à une ligne de vente (réservation),
     * et si son approvisionnement est validé (status 'received') ou s'il n'a pas de ligne d'approvisionnement.
     */
    public function scopeAvailable($query)
    {
        return $query->where('is_sold', false)
            ->whereNull('sale_line_item_id')
            ->where(function ($q) {
                $q->whereNull('supply_line_item_id')
                  ->orWhereHas('supplyLineItem.supply', function ($supplyQuery) {
                      $supplyQuery->where('status', 'received');
                  });
            });
    }

    /**
     * Scope pour les numéros de série en stock physique.
     * Un numéro de série est en stock s'il n'est pas encore marqué comme vendu
     * et si son approvisionnement est validé (status 'received') ou s'il n'a pas de ligne d'approvisionnement.
     */
    public function scopeInStock($query)
    {
        return $query
            ->where('is_sold', false)
            ->where(function ($q) {
                $q->whereNull('supply_line_item_id')
                  ->orWhereHas('supplyLineItem.supply', function ($supplyQuery) {
                      $supplyQuery->where('status', 'received');
                  });
            });
    }

    /**
     * Scope pour inclure tous les numéros de série (même vendus).
     * Utile pour l'affichage de l'historique tout en filtrant sur le statut de l'approvisionnement.
     */
    public function scopeWithHistory($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('supply_line_item_id')
              ->orWhereHas('supplyLineItem.supply', function ($supplyQuery) {
                  $supplyQuery->where('status', 'received');
              });
        });
    }

    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at'
    ];
}
