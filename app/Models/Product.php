<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Product extends Model implements HasMedia
{
    use HasFactory, SoftDeletes, InteractsWithMedia;

    public const DEFAULT_UNIT_WITHOUT_MEASUREMENTS = 'piece';

    protected $fillable = [
        'store_id',
        'category_id',
        'name',
        'description',
        'require_serial_number',
        'alert_threshold',
        'base_unit', // Nouveau: unité de base (sac, kg, pièce, etc.)
        'base_unit_quantity', // Stock physique en unité de base
    ];

    /**
     * Relation avec le modèle Store
     */
    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Relation avec les unités de mesure
     * Un produit a plusieurs unités de vente
     */
    public function unitOfMeasures()
    {
        return $this->hasMany(UnitOfMeasure::class);
    }

    /**
     * Relation avec l'unité de mesure de base
     */
    public function baseUnitOfMeasure()
    {
        return $this->hasOne(UnitOfMeasure::class)->where('is_base_unit', true);
    }

    /**
     * Relation avec le modèle Category
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Scope pour les unités de vente seulement (non base)
     */
    public function scopeSellingUnits($query)
    {
        return $query->where('is_base_unit', false);
    }

    public function serialNumbers()
    {
        return $this->hasMany(SerialNumber::class);
    }

    /**
     * Numéros de série physiquement en stock (non vendus).
     */
    public function countUnsoldSerials(): int
    {
        return $this->serialNumbers()->inStock()->count();
    }

    /**
     * Numéros de série libres (non vendus et non réservés par une vente pending).
     */
    public function countAvailableSerials(): int
    {
        return $this->serialNumbers()
            ->available()
            ->count();
    }

    /**
     * Pour les produits avec numéro de série, aligne base_unit_quantity sur le stock réel.
     */
    public function syncStockFromSerialNumbers(): void
    {
        if (!$this->require_serial_number) {
            return;
        }

        $this->base_unit_quantity = $this->countUnsoldSerials();
        $this->save();
    }

    /**
     * Accessor pour le stock avec différentes unités
     */
    public function getStockAttribute()
    {
        return [
            'base_quantity' => $this->base_unit_quantity,
            'base_unit' => $this->base_unit,
            'equivalences' => $this->calculateStockEquivalences()
        ];
    }

    /**
     * Calcul des équivalences de stock
     */
    private function calculateStockEquivalences()
    {
        $equivalences = [];
        foreach ($this->unitOfMeasures as $uom) {
            if (!$uom->is_base_unit) {
                $equivalences[$uom->name] = [
                    'quantity' => $this->base_unit_quantity / $uom->conversion_factor,
                    'price' => $uom->price,
                    'conversion_factor' => $uom->conversion_factor
                ];
            }
        }
        return $equivalences;
    }



    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')
            ->singleFile()
            ->useDisk('products');
    }

    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at'
    ];
}
