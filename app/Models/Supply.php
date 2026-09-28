<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supply extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'order_number',
        'store_id',
        'supplier_id',
        'user_id',
        'status',
        'total_amount',
        'received_at',
        'received_by',
    ];

    protected $casts = [
        'received_at' => 'datetime',
    ];

    /**
     * Un approvisionnement appartient à un magasin.
     */
    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Un approvisionnement appartient à un fournisseur.
     */
    public function supplier()
    {
        return $this->belongsTo(Supplierproduct::class);
    }

    public function supplyLineItems()
    {
        return $this->hasMany(SupplyLineItem::class);
    }

    /**
     * Un approvisionnement est enregistré par un utilisateur.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Utilisateur qui a validé la réception (entrée en stock).
     */
    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($supply) {
            if (empty($supply->order_number)) {
                $supply->order_number = self::generateOrderNumber($supply->store_id);
            }
        });
    }

    /**
     * Génère un numéro de commande unique du type STR{store_id}-{count}-{year}
     * Exemple : STR1-001-25
     */
    private static function generateOrderNumber($storeId)
    {
        $year = now()->format('y'); // ex: "25" pour 2025

        // Compte combien de supplies ont été créées pour ce store cette année
        // (y compris supprimées : leur numéro reste pris par la contrainte unique)
        $count = self::withTrashed()
            ->where('store_id', $storeId)
            ->whereYear('created_at', now()->year)
            ->count() + 1;

        // Formate le compteur avec 3 chiffres (ex: 001, 002, etc.)
        $formattedCount = str_pad($count, 3, '0', STR_PAD_LEFT);

        return "STR{$storeId}-{$formattedCount}-{$year}";
    }
}

