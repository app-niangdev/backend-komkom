<?php

return [
    // Nombre de jours avant l'échéance à partir duquel on prévient les utilisateurs à la connexion
    'warning_days' => (int) env('SUBSCRIPTION_WARNING_DAYS', 5),

    // Période d'essai offerte à la création d'une boutique (0 = aucune, la boutique est bloquée
    // tant qu'un administrateur n'a pas saisi d'abonnement)
    'trial_days' => (int) env('SUBSCRIPTION_TRIAL_DAYS', 30),

    'default_currency' => env('SUBSCRIPTION_CURRENCY', 'XOF'),
];
