<?php

namespace App\Support;

/**
 * Numéro au format attendu par https://wa.me/ : indicatif pays + numéro, chiffres seuls, sans « + » ni « 00 ».
 */
final class WhatsappNumber
{
    public static function normalize(?string $phone): ?string
    {
        $raw = trim((string) $phone);
        if ($raw === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw);
        $international = str_starts_with($raw, '+') || str_starts_with($digits, '00');
        $digits = ltrim(str_starts_with($digits, '00') ? substr($digits, 2) : $digits, '0');

        if (!$international && strlen($digits) === config('storefront.national_number_length')) {
            $digits = config('storefront.default_country_code') . $digits;
        }

        // E.164 : 15 chiffres au plus ; en dessous de 10, il manque l'indicatif ou des chiffres
        return strlen($digits) >= 10 && strlen($digits) <= 15 ? $digits : null;
    }
}
