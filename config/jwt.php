<?php

return [

    'secret' => env('JWT_SECRET'),

    'algo' => 'HS256',

    'access_ttl' => (int) env('JWT_ACCESS_TTL', 15),

    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 1440),

    'refresh_ttl_remember' => (int) env('JWT_REFRESH_TTL_REMEMBER', 43200),

    'cookie_name' => env('AUTH_COOKIE_NAME', 'access_token'),

    'cookie_domain' => env('COOKIE_DOMAIN'),

    'cookie_secure' => filter_var(env('COOKIE_SECURE', true), FILTER_VALIDATE_BOOLEAN),

    'cookie_samesite' => env('COOKIE_SAMESITE', 'lax'),

];
