<?php

return [

    // Echecs consecutifs toleres avant que l'IP ne soit bloquee.
    'max_attempts' => (int) env('LOGIN_MAX_ATTEMPTS', 5),

    // Duree du premier blocage, en secondes. Les suivantes la doublent.
    'base_block_seconds' => (int) env('LOGIN_BASE_BLOCK_SECONDS', 60),

    // Plafond : 24 h. Au-dela, le doublement cesse.
    'max_block_seconds' => (int) env('LOGIN_MAX_BLOCK_SECONDS', 86400),

    'otp' => [
        // Duree de vie d'un code, en secondes.
        'ttl' => (int) env('LOGIN_OTP_TTL', 300),

        // Saisies erronees tolerees pour un meme challenge.
        'max_attempts' => (int) env('LOGIN_OTP_MAX_ATTEMPTS', 5),

        // Renvois de code autorises par challenge.
        'max_resend' => (int) env('LOGIN_OTP_MAX_RESEND', 3),

        // Delai minimal entre deux envois, en secondes.
        'resend_cooldown' => (int) env('LOGIN_OTP_RESEND_COOLDOWN', 60),
    ],

    // Nettoyage periodique.
    'prune' => [
        'security_days' => (int) env('LOGIN_PRUNE_SECURITY_DAYS', 30),
        'challenges_days' => (int) env('LOGIN_PRUNE_CHALLENGES_DAYS', 7),
    ],
];
