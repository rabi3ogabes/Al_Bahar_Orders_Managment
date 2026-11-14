<?php

namespace App\Support;

/**
 * Phone normalisation to E.164. Qatar (+974) mobile numbers are 8 digits starting with 3, 5, 6 or 7.
 * Additional country codes can be enabled via config('platform.phone.allowed_country_codes').
 */
final class Phone
{
    /** Country-specific national-number rules. Codes not listed accept 6–12 digits. */
    private const RULES = [
        '974' => '/^[3567]\d{7}$/',
    ];

    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }
        // Convert Arabic-Indic and Eastern Arabic-Indic digits.
        $input = strtr($input, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $digits = preg_replace('/\D+/', '', $input) ?? '';
        if (str_starts_with(trim($input), '00')) {
            $digits = substr($digits, 2);
        }
        if ($digits === '') {
            return null;
        }

        $allowed = config('platform.phone.allowed_country_codes', ['974']);
        foreach ($allowed as $cc) {
            if (str_starts_with($digits, $cc) && self::nationalValid($cc, substr($digits, strlen($cc)))) {
                return '+'.$digits;
            }
        }
        // Bare national number → default country.
        $default = config('platform.phone.default_country_code', '974');
        if (in_array($default, $allowed, true) && self::nationalValid($default, $digits)) {
            return '+'.$default.$digits;
        }

        return null;
    }

    public static function isValid(?string $input): bool
    {
        return self::normalize($input) !== null;
    }

    /** Whether two numbers are one and the same line once both are in E.164 ("55123456" and "+974 5512 3456"). */
    public static function same(?string $a, ?string $b): bool
    {
        $a = self::normalize($a);

        return $a !== null && $a === self::normalize($b);
    }

    /** Masked for logs and admin lists: +974 55•••123 */
    public static function mask(?string $e164): ?string
    {
        if ($e164 === null || strlen($e164) < 7) {
            return $e164;
        }

        return substr($e164, 0, 6).str_repeat('•', max(strlen($e164) - 9, 2)).substr($e164, -3);
    }

    private static function nationalValid(string $cc, string $national): bool
    {
        $rule = self::RULES[$cc] ?? '/^\d{6,12}$/';

        return (bool) preg_match($rule, $national);
    }
}
