<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Store extends Model implements HasMedia
{
    use HasFactory, SoftDeletes, InteractsWithMedia;

    protected $fillable = [
        'company_id',
        'name',
        'slogan',
        'address',
        'phone_one',
        'phone_two',
        'phone_three',
        'email',
        'active',
        'uses_measurements',
        'uses_serial_numbers',
        'ticket_width',
        'use_company_logo',
        'use_company_colors',
        'primary_color',
        'secondary_color',
    ];

    /** Largeurs de rouleau prises en charge pour les tickets de caisse (mm). */
    public const TICKET_WIDTHS = [58, 80];

    public const SERIALS_IN_USE_MESSAGE = 'Des produits de la boutique sont suivis par numéro de série : '
        . 'retirez d\'abord ce suivi (ou supprimez ces produits) avant de désactiver les numéros de série.';

    protected $casts = [
        'active' => 'boolean',
        'uses_measurements' => 'boolean',
        'uses_serial_numbers' => 'boolean',
        'ticket_width' => 'integer',
        // Envoi WhatsApp des factures : modifiable uniquement par l'administrateur (hors $fillable)
        'whatsapp_invoices_enabled' => 'boolean',
        'use_company_logo' => 'boolean',
        'use_company_colors' => 'boolean',
        // Vitrine : modifiables uniquement par l'administrateur (hors $fillable, via StorefrontService)
        'storefront_enabled' => 'boolean',
        'storefront_enabled_at' => 'datetime',
    ];

    protected $appends = ['logo_url', 'effective_primary_color', 'effective_secondary_color'];

    /**
     * Relation avec le modèle Owner
     * Un store appartient à un owner.
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')
            ->singleFile()
            ->useDisk('stores');
    }

    /**
     * Retourne l'URL du logo propre au store, ou celui de la société
     * si le store est configuré pour utiliser le logo du tenant.
     */
    public function getLogoUrlAttribute()
    {
        if (!$this->use_company_logo) {
            $media = $this->getFirstMedia('logo');
            if ($media) {
                return $media->getUrl();
            }
        }

        return $this->company?->logo_url;
    }

    public function getEffectivePrimaryColorAttribute()
    {
        if (!$this->use_company_colors && $this->primary_color) {
            return $this->primary_color;
        }

        return $this->company?->primary_color;
    }

    public function getEffectiveSecondaryColorAttribute()
    {
        if (!$this->use_company_colors && $this->secondary_color) {
            return $this->secondary_color;
        }

        return $this->company?->secondary_color;
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class)->orderBy('starts_at');
    }

    /**
     * Des produits suivis par numéro de série existent : on ne peut pas désactiver
     * les numéros de série de la boutique (leurs IMEI deviendraient invisibles).
     */
    public function hasSerialProducts(): bool
    {
        return $this->products()->where('require_serial_number', true)->exists();
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function managers(): HasMany
    {
        return $this->hasMany(Manager::class)->with('user');
    }

    public function sellers(): HasMany
    {
        return $this->hasMany(Seller::class);
    }

    public function supplierproducts(): HasMany
    {
        return $this->hasMany(Supplierproduct::class);
    }

    protected $hidden = [
        'deleted_at',
        'created_at',
        'updated_at'
    ];
}
