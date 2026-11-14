<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Which version of the platform is running and when it was last updated, for the dashboard footer.
 *
 * The release script bakes `bootstrap/build.json` ({"version": "1.0.412", "commit": "8e3f1a2", "built_at": "2026-11-14T16:08:00Z"}) into the
 * image, so the footer changes with every update. Where that file is missing (local work, an image built by hand) the version reads "dev"
 * and the time falls back to when the front-end assets were last built.
 */
final class BuildInfo
{
    /** @return array{version: string, commit: ?string, built_at: ?CarbonImmutable, dev: bool} */
    public static function get(?string $file = null): array
    {
        $file ??= base_path('bootstrap/build.json');
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $data = is_array($data) ? $data : [];

        $version = self::clean($data['version'] ?? null) ?? 'dev';
        $commit = self::clean($data['commit'] ?? null);
        $builtAt = null;
        if (is_string($data['built_at'] ?? null) && $data['built_at'] !== '') {
            try {
                $builtAt = CarbonImmutable::parse($data['built_at']);
            } catch (\Throwable) {
                $builtAt = null;
            }
        }
        $builtAt ??= self::assetsBuiltAt();

        return ['version' => $version, 'commit' => $commit, 'built_at' => $builtAt, 'dev' => $version === 'dev'];
    }

    /** Only plain version-like text is shown: letters, digits, dot, dash, underscore, plus. */
    private static function clean(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9._+-]{1,40}$/', $value) ? $value : null;
    }

    private static function assetsBuiltAt(): ?CarbonImmutable
    {
        $manifest = public_path('build/manifest.json');

        return is_file($manifest) ? CarbonImmutable::createFromTimestamp((int) filemtime($manifest)) : null;
    }
}
