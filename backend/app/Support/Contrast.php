<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * WCAG 2.x colour maths on plain "#RRGGBB" strings: relative luminance, contrast ratio and a simple mix.
 * Pure and side-effect free, so a colour picked in the dashboard can be checked for legibility before it is saved.
 */
final class Contrast
{
    /** WCAG AA for normal-size text. */
    public const AA = 4.5;

    public static function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1;
    }

    /** Relative luminance, 0 (black) to 1 (white). */
    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(static function (int $channel): float {
            $c = $channel / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /** Contrast ratio between two colours, 1 (identical) to 21 (black on white). Order does not matter. */
    public static function ratio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    public static function meets(string $a, string $b, float $min = self::AA): bool
    {
        return self::ratio($a, $b) >= $min;
    }

    /** $hex moved $amount (0 to 1) of the way towards $with, as "#RRGGBB" upper-case. */
    public static function mix(string $hex, string $with, float $amount): string
    {
        $amount = max(0.0, min(1.0, $amount));
        $from = self::rgb($hex);
        $to = self::rgb($with);
        $out = array_map(static fn (int $f, int $t): int => (int) round($f + ($t - $f) * $amount), $from, $to);

        return sprintf('#%02X%02X%02X', ...$out);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        if (! self::isHex($hex)) {
            throw new InvalidArgumentException('Expected a colour like #RRGGBB.');
        }

        return [(int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2))];
    }
}
