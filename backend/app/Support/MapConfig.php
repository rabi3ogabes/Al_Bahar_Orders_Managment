<?php

namespace App\Support;

use App\Domain\Settings\Services\Settings;
use Illuminate\Support\Facades\Cache;

/**
 * The one map configuration every client reads: the admin dashboard (Blade), the partner page, the web store and the
 * mobile apps (GET /api/v1/map-config). Operators pick the tile provider in Settings → Integrations (map.* keys).
 *
 * Tile URLs for MapTiler/Stadia carry the API key in the query string, as those providers intend: the key is public by
 * design, so restrict it to the site's domains and the apps' IDs in the provider's console. Nothing else secret is ever
 * part of the payload, and the routing URL (used server-side for ETAs) is never included.
 *
 * Placeholders left in the URLs for the client to fill: {z} {x} {y}, {s} (one of tiles.subdomains) and {r}
 * ("@2x" on high-density screens, only present when tiles.retina is true).
 */
final class MapConfig
{
    public const PROVIDERS = ['osm', 'maptiler', 'stadia', 'carto', 'custom'];

    private const CACHE_KEY = 'map_config:v1';

    private const CACHE_SECONDS = 300;

    private const OSM_CREDIT = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

    /** Placeholders a tile template may contain. */
    private const PLACEHOLDERS = ['z', 'x', 'y', 's', 'r', 'key'];

    /** @var array<string, array{light: string, dark: ?string, attribution: string, max_zoom: int, subdomains: string, needs_key: bool}> */
    private const PRESETS = [
        'osm' => [
            'light' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', 'dark' => null,
            'attribution' => self::OSM_CREDIT, 'max_zoom' => 19, 'subdomains' => '', 'needs_key' => false,
        ],
        'maptiler' => [
            'light' => 'https://api.maptiler.com/maps/streets-v2/{z}/{x}/{y}{r}.png?key={key}',
            'dark' => 'https://api.maptiler.com/maps/streets-v2-dark/{z}/{x}/{y}{r}.png?key={key}',
            'attribution' => '&copy; <a href="https://www.maptiler.com/copyright/">MapTiler</a> '.self::OSM_CREDIT,
            'max_zoom' => 20, 'subdomains' => '', 'needs_key' => true,
        ],
        'stadia' => [
            'light' => 'https://tiles.stadiamaps.com/tiles/alidade_smooth/{z}/{x}/{y}{r}.png?api_key={key}',
            'dark' => 'https://tiles.stadiamaps.com/tiles/alidade_smooth_dark/{z}/{x}/{y}{r}.png?api_key={key}',
            'attribution' => '&copy; <a href="https://stadiamaps.com/">Stadia Maps</a> &copy; <a href="https://openmaptiles.org/">OpenMapTiles</a> '.self::OSM_CREDIT,
            'max_zoom' => 20, 'subdomains' => '', 'needs_key' => true,
        ],
        'carto' => [
            'light' => 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
            'dark' => 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
            'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
            'max_zoom' => 20, 'subdomains' => 'abcd', 'needs_key' => false,
        ],
    ];

    /**
     * @return array{
     *     provider: string,
     *     tiles: array{light_url: string, dark_url: string, attribution: string, attribution_text: string, subdomains: string, max_zoom: int, retina: bool},
     *     center: array{lat: float, lng: float},
     *     zoom: int,
     *     stale_after_seconds: int
     * } cached for five minutes; saving any map.* setting drops the cache
     */
    public static function payload(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => self::build(app(Settings::class)));
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Why the saved settings are not used as chosen, or null when they are: 'missing_key' (the provider needs an API key,
     * OpenStreetMap is shown instead) or 'invalid_template' (a custom provider without a valid light tile URL).
     */
    public static function problem(): ?string
    {
        $s = app(Settings::class);
        $provider = self::provider($s);
        if ($provider === 'custom') {
            return self::tileTemplateError((string) $s->get('map.tile_url_light')) === null ? null : 'invalid_template';
        }

        return self::PRESETS[$provider]['needs_key'] && trim((string) $s->get('map.api_key')) === '' ? 'missing_key' : null;
    }

    /** One real tile of the downtown Doha area from the saved configuration, for the "Test tiles" check. */
    public static function sampleTileUrl(): string
    {
        $p = self::build(app(Settings::class));
        $t = Geo::tile($p['center']['lat'], $p['center']['lng'], 11);

        return strtr($p['tiles']['light_url'], ['{z}' => '11', '{x}' => (string) $t['x'], '{y}' => (string) $t['y'], '{s}' => $p['tiles']['subdomains'][0] ?? '', '{r}' => '']);
    }

    /** The routing service base URL (no trailing slash) when one is configured and valid; it is only ever called by the server. */
    public static function routingUrl(): ?string
    {
        $url = trim((string) app(Settings::class)->get('map.routing_url'));

        return $url !== '' && self::serviceUrlError($url) === null ? rtrim($url, '/') : null;
    }

    /** Error code ('empty', 'too_long', 'https', 'placeholders', 'unknown_placeholder', 'host', 'credentials') or null when the template is usable. */
    public static function tileTemplateError(string $template): ?string
    {
        $template = trim($template);
        if ($template === '') {
            return 'empty';
        }
        if (strlen($template) > 500) {
            return 'too_long';
        }
        if (! str_starts_with(strtolower($template), 'https://')) {
            return 'https';
        }
        foreach (['z', 'x', 'y'] as $required) {
            if (! str_contains($template, '{'.$required.'}')) {
                return 'placeholders';
            }
        }
        preg_match_all('/\{([^{}]*)\}/', $template, $found);
        foreach ($found[1] as $name) {
            if (! in_array($name, self::PLACEHOLDERS, true)) {
                return 'unknown_placeholder';
            }
        }

        return self::hostError(preg_replace('/\{[^{}]*\}/', 'a', $template) ?? '');
    }

    /** Error code ('https', 'too_long', 'host', 'credentials', 'query') or null when the routing base URL is usable. */
    public static function serviceUrlError(string $url): ?string
    {
        $url = trim($url);
        if (strlen($url) > 300) {
            return 'too_long';
        }
        if (! str_starts_with(strtolower($url), 'https://')) {
            return 'https';
        }
        if (str_contains($url, '?') || str_contains($url, '#')) {
            return 'query';
        }

        return self::hostError($url);
    }

    /** Keeps plain text and https links only; anything else is escaped, so the credit line is safe to render as HTML. */
    public static function cleanAttribution(string $html): string
    {
        $out = '';
        $open = false;
        foreach (preg_split('~(<a href="https://[^"<>\s]{1,300}">|</a>)~i', trim($html), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $part) {
            if (preg_match('~^<a href="https://[^"<>\s]+">$~i', $part)) {
                if (! $open) {
                    $out .= $part;
                    $open = true;
                }

                continue;
            }
            if (strcasecmp($part, '</a>') === 0) {
                if ($open) {
                    $out .= '</a>';
                    $open = false;
                }

                continue;
            }
            $out .= str_replace(['<', '>', '"'], ['&lt;', '&gt;', '&quot;'], $part);
        }

        return $out.($open ? '</a>' : '');
    }

    /** @return array<string, mixed> */
    private static function build(Settings $s): array
    {
        $provider = self::provider($s);
        $key = rawurlencode(trim((string) $s->get('map.api_key')));
        $preset = self::PRESETS[$provider] ?? null;

        if ($provider === 'custom') {
            $light = trim((string) $s->get('map.tile_url_light'));
            if (self::tileTemplateError($light) !== null) {
                $provider = 'osm';
                $preset = self::PRESETS['osm'];
            } else {
                $dark = trim((string) $s->get('map.tile_url_dark'));
                $preset = ['light' => $light, 'dark' => $dark !== '' && self::tileTemplateError($dark) === null ? $dark : null, 'attribution' => '',
                    'max_zoom' => 19, 'subdomains' => str_contains($light.$dark, '{s}') ? 'abc' : '', 'needs_key' => false];
            }
        } elseif ($preset['needs_key'] && $key === '') {
            // A key-only provider without a key would show grey squares; fall back so the maps always work.
            $provider = 'osm';
            $preset = self::PRESETS['osm'];
        }

        $wantsRetina = (bool) $s->get('map.retina', true);
        $light = self::fill($preset['light'], $key, $wantsRetina);
        $dark = self::fill($preset['dark'] ?? $preset['light'], $key, $wantsRetina);
        $retina = $wantsRetina && (str_contains($preset['light'], '{r}') || str_contains((string) $preset['dark'], '{r}'));

        $credit = trim((string) $s->get('map.attribution'));
        $credit = self::cleanAttribution($credit !== '' ? $credit : $preset['attribution']);
        $maxZoom = (int) $s->get('map.max_zoom');

        $lat = $s->get('map.center_lat');
        $lng = $s->get('map.center_lng');
        $usable = is_numeric($lat) && is_numeric($lng) && Geo::validPoint((float) $lat, (float) $lng) && abs((float) $lat) <= 85;
        $zoom = (int) $s->get('map.zoom');

        return [
            'provider' => $provider,
            'tiles' => [
                'light_url' => $light, 'dark_url' => $dark,
                'attribution' => $credit, 'attribution_text' => trim(html_entity_decode(strip_tags($credit), ENT_QUOTES | ENT_HTML5)),
                'subdomains' => $preset['subdomains'],
                'max_zoom' => $maxZoom >= 1 && $maxZoom <= 22 ? $maxZoom : $preset['max_zoom'],
                'retina' => $retina,
            ],
            'center' => $usable ? ['lat' => (float) $lat, 'lng' => (float) $lng] : ['lat' => 25.2854, 'lng' => 51.5310],
            'zoom' => $zoom >= 3 && $zoom <= 19 ? $zoom : 11,
            'stale_after_seconds' => (int) config('platform.tracking.stale_after_seconds', 120),
        ];
    }

    private static function provider(Settings $s): string
    {
        $p = (string) $s->get('map.provider', 'osm');

        return in_array($p, self::PROVIDERS, true) ? $p : 'osm';
    }

    /** Substitutes the key and settles the {r} placeholder: kept when high-density tiles are wanted and the template supports them. */
    private static function fill(string $template, string $encodedKey, bool $retina): string
    {
        $template = str_replace('{key}', $encodedKey, $template);

        return $retina ? $template : str_replace('{r}', '', $template);
    }

    private static function hostError(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            return 'host';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'credentials';
        }
        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return 'host';
        }
        // Loopback, link-local (cloud metadata) and other reserved literals are refused; a private LAN address is the
        // legitimate home of a self-hosted routing server.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false) {
            return 'host';
        }

        return null;
    }
}
