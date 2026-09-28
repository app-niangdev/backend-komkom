<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Store;
use App\Support\WhatsappNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Vitrine publique d'une boutique : activation (réservée à l'administrateur), slug, et
 * règles de ce qui est exposé sans authentification.
 *
 * Une vitrine est en ligne si elle est activée, la boutique active et son abonnement en cours.
 * Seuls les produits en stock (quantité > 0) sont publiés, avec un simple indicateur de stock.
 */
class StorefrontService
{
    public function __construct(protected SubscriptionService $subscriptions)
    {
    }

    /** Boutique dont la vitrine est en ligne, sinon 404 (sans dire pourquoi : rien ne fuit). */
    public function findOnline(string $slug): Store
    {
        $store = Store::with(['company', 'media', 'company.media'])
            ->where('slug', mb_strtolower($slug))
            ->where('storefront_enabled', true)
            ->where('active', true)
            ->first();

        abort_if(!$store || !$this->subscriptions->isAccessible($store), 404, 'Cette vitrine n\'est pas disponible.');

        return $store;
    }

    /** Produits publiés d'une boutique : en stock uniquement. */
    public function publishedProducts(Store $store): Builder
    {
        return Product::query()
            ->where('store_id', $store->id)
            ->where('base_unit_quantity', '>', 0);
    }

    /**
     * État de la vitrine pour l'administrateur et l'équipe de la boutique : lien et raisons
     * pour lesquelles elle n'est pas (ou ne serait pas) en ligne.
     */
    public function status(Store $store): array
    {
        $whatsapp = WhatsappNumber::normalize($store->phone_one);
        $issues = [];
        if (!$whatsapp) {
            $issues[] = 'Le téléphone principal de la boutique n\'est pas un numéro WhatsApp valide.';
        }
        if (!$store->active) {
            $issues[] = 'La boutique est désactivée.';
        }
        if (!$this->subscriptions->isAccessible($store)) {
            $issues[] = 'L\'abonnement de la boutique n\'est pas en cours.';
        }

        return [
            'enabled' => (bool) $store->storefront_enabled,
            'online' => $store->storefront_enabled && !$issues,
            'slug' => $store->slug,
            'url' => $store->slug ? $this->url($store->slug) : null,
            'whatsapp' => $whatsapp,
            'enabled_at' => $store->storefront_enabled_at?->toIso8601String(),
            'issues' => $issues,
        ];
    }

    /** Version lecture seule pour l'équipe de la boutique : le lien n'est donné qu'une fois activé. */
    public function link(Store $store): array
    {
        $status = $this->status($store);

        return ['enabled' => $status['enabled'], 'online' => $status['online'], 'url' => $status['enabled'] ? $status['url'] : null];
    }

    /** Active / désactive la vitrine et change son slug (un slug est proposé à la première activation). */
    public function configure(Store $store, bool $enabled, ?string $slug): Store
    {
        $slug = $slug !== null && trim($slug) !== '' ? mb_strtolower(trim($slug)) : null;

        if ($slug !== null) {
            $this->assertSlugAvailable($slug, $store);
        } elseif ($enabled && !$store->slug) {
            $slug = $this->suggestSlug($store->name, $store);
        }

        if ($enabled && !WhatsappNumber::normalize($store->phone_one)) {
            throw ValidationException::withMessages([
                'enabled' => 'Impossible d\'activer la vitrine : le téléphone principal de la boutique (« '
                    . ($store->phone_one ?: 'vide') . ' ») n\'est pas un numéro WhatsApp valide.',
            ]);
        }

        $store->forceFill([
            'slug' => $slug ?? $store->slug,
            'storefront_enabled' => $enabled,
            'storefront_enabled_at' => $enabled ? ($store->storefront_enabled ? $store->storefront_enabled_at : now()) : null,
        ])->save();

        return $store;
    }

    public function url(string $slug): string
    {
        return config('storefront.url') . '/' . $slug;
    }

    /** Slug libre dérivé du nom : « Magasin Centre-Ville » -> magasin-centre-ville(-2...). */
    public function suggestSlug(string $name, ?Store $ignore = null): string
    {
        $base = Str::limit(Str::slug($name), 50, '') ?: 'boutique';
        if (in_array($base, config('storefront.reserved_slugs'), true) || strlen($base) < 3) {
            $base = 'boutique-' . $base;
        }

        $slug = $base;
        for ($i = 2; $this->slugTaken($slug, $ignore); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    private function assertSlugAvailable(string $slug, Store $store): void
    {
        $error = match (true) {
            !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) => 'Le lien ne peut contenir que des lettres minuscules sans accent, des chiffres et des tirets.',
            strlen($slug) < 3 || strlen($slug) > 60 => 'Le lien doit faire entre 3 et 60 caractères.',
            in_array($slug, config('storefront.reserved_slugs'), true) => "« {$slug} » est réservé : choisissez un autre lien.",
            $this->slugTaken($slug, $store) => "Le lien « {$slug} » est déjà utilisé par une autre boutique.",
            default => null,
        };

        if ($error) {
            throw ValidationException::withMessages(['slug' => $error]);
        }
    }

    private function slugTaken(string $slug, ?Store $ignore): bool
    {
        // Les boutiques supprimées gardent leur slug (contrainte unique en base)
        return Store::withTrashed()
            ->where('slug', $slug)
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->id))
            ->exists();
    }
}
