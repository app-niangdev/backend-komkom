<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter([
        'https://komkom.niangdev.com',
        'https://store.niangdev.com',
        'http://164.132.85.222:8081',
        // Application vitrine (appels depuis le navigateur après le rendu SSR)
        env('STOREFRONT_URL'),
    ])),
    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
