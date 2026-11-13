<?php

namespace App\Support;

/** A short, human label for a browser from its user-agent string ("Chrome on Android"). Only the family is kept, never the full string. */
final class BrowserFamily
{
    public static function from(?string $userAgent): ?string
    {
        $ua = (string) $userAgent;
        if ($ua === '') {
            return null;
        }
        // Order matters: Edge, Opera and Samsung Internet also say "Chrome"; Chrome on iOS says "CriOS" and Firefox on iOS "FxiOS".
        $browser = match (true) {
            (bool) preg_match('/\b(Edg|EdgA|EdgiOS)\//', $ua) => 'Edge',
            (bool) preg_match('/\b(OPR|Opera)\//', $ua) => 'Opera',
            str_contains($ua, 'SamsungBrowser/') => 'Samsung Internet',
            (bool) preg_match('/\b(Firefox|FxiOS)\//', $ua) => 'Firefox',
            (bool) preg_match('/\b(Chrome|CriOS)\//', $ua) => 'Chrome',
            str_contains($ua, 'Safari/') && str_contains($ua, 'Version/') => 'Safari',
            default => null,
        };
        $os = match (true) {
            str_contains($ua, 'Android') => 'Android',
            (bool) preg_match('/iPhone/', $ua) => 'iPhone',
            (bool) preg_match('/iPad/', $ua) => 'iPad',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        return match (true) {
            $browser !== null && $os !== null => "$browser on $os",
            default => $browser ?? $os,
        };
    }
}
