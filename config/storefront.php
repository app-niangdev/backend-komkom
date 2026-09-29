<?php

return [
    // Adresse publique de l'application vitrine (Angular SSR) : le lien d'une boutique est `{url}/{slug}`
    'url' => rtrim((string) env('STOREFRONT_URL', 'http://localhost:4300'), '/'),

    // Indicatif ajouté aux numéros saisis au format national (ex. 771234567 -> 221771234567)
    'default_country_code' => (string) env('STOREFRONT_COUNTRY_CODE', '221'),

    // Longueur d'un numéro national sans indicatif (Sénégal : 9 chiffres)
    'national_number_length' => (int) env('STOREFRONT_NATIONAL_LENGTH', 9),

    // Durée (secondes) pendant laquelle les navigateurs / le SSR peuvent réutiliser une réponse publique
    'cache_seconds' => (int) env('STOREFRONT_CACHE_SECONDS', 30),

    // Limites de l'API publique : par IP (navigateurs) et pour le serveur SSR (en-tête X-Storefront-Key)
    'rate_per_minute' => (int) env('STOREFRONT_RATE_PER_MINUTE', 120),
    'ssr_key' => (string) env('STOREFRONT_SSR_KEY', ''),
    'ssr_rate_per_minute' => (int) env('STOREFRONT_SSR_RATE_PER_MINUTE', 3000),

    // Slugs interdits : ils entreraient en conflit avec des pages de la vitrine
    'reserved_slugs' => ['api', 'admin', 'www', 'app', 'assets', 'static', 'panier', 'cart', 'boutique', 'boutiques', 'aide', 'contact', 'healthz'],
];
