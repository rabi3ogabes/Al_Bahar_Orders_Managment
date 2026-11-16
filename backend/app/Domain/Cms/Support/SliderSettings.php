<?php

namespace App\Domain\Cms\Support;

/**
 * The look and behaviour of one home slider (a `banners` home-page section), stored in homepage_sections.settings.slider.
 * One place for the defaults and the allowed values, so the dashboard, the API and the stores always agree.
 */
final class SliderSettings
{
    public const ASPECTS = ['16:9', '21:9', '3:1', '4:3', '1:1'];

    public const MIN_INTERVAL = 3;

    public const MAX_INTERVAL = 15;

    /** More slides than this in one slider is a worse experience than none; the dashboard stops here. */
    public const MAX_SLIDES = 20;

    public const DEFAULTS = ['autoplay' => true, 'interval' => 5, 'aspect' => '16:9', 'dots' => true, 'show_titles' => true, 'rounded' => true];

    /** The recommended artwork size (pixels) per aspect ratio, shown next to the upload. */
    public const RECOMMENDED = ['16:9' => [1600, 900], '21:9' => [2100, 900], '3:1' => [2400, 800], '4:3' => [1600, 1200], '1:1' => [1200, 1200]];

    /**
     * Any stored value (missing, hand-edited, out of range) becomes a complete, valid settings array.
     *
     * @param  array<string, mixed>|null  $raw
     * @return array{autoplay: bool, interval: int, aspect: string, dots: bool, show_titles: bool, rounded: bool}
     */
    public static function normalize(?array $raw): array
    {
        $raw ??= [];
        $bool = fn (string $key): bool => array_key_exists($key, $raw) && $raw[$key] !== null ? filter_var($raw[$key], FILTER_VALIDATE_BOOLEAN) : self::DEFAULTS[$key];
        $interval = $raw['interval'] ?? self::DEFAULTS['interval'];
        $interval = is_numeric($interval) ? (int) $interval : self::DEFAULTS['interval'];
        $aspect = $raw['aspect'] ?? null;

        return [
            'autoplay' => $bool('autoplay'),
            'interval' => max(self::MIN_INTERVAL, min(self::MAX_INTERVAL, $interval)),
            'aspect' => is_string($aspect) && in_array($aspect, self::ASPECTS, true) ? $aspect : self::DEFAULTS['aspect'],
            'dots' => $bool('dots'),
            'show_titles' => $bool('show_titles'),
            'rounded' => $bool('rounded'),
        ];
    }

    /** CSS aspect-ratio value for an aspect string (`16:9` → `16 / 9`). */
    public static function cssRatio(string $aspect): string
    {
        return str_replace(':', ' / ', in_array($aspect, self::ASPECTS, true) ? $aspect : self::DEFAULTS['aspect']);
    }

    /** @return array{0: int, 1: int} recommended width and height in pixels */
    public static function recommended(string $aspect): array
    {
        return self::RECOMMENDED[$aspect] ?? self::RECOMMENDED['16:9'];
    }
}
