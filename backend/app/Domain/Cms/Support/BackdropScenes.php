<?php

namespace App\Domain\Cms\Support;

/**
 * The built-in Qatar artworks the page background can show (docs/api/PAGE_BACKDROP.md, "Models"): one registry for the service,
 * the dashboard page and the tests. The drawings are generated (scripts/qatar-skyline/build.py and build_scenes.py) into
 * public/brand/, never edited by hand. Names live in lang/{en,ar}/backdrop_admin.php (`scene_<key>`), so the English and Arabic text
 * is written once and checked by the language tests.
 *
 * Five are scenes that stand on the bottom edge (box 1600 x 340); `arabesque` is a tile: one 200 x 200 cell that repeats over the page.
 */
final class BackdropScenes
{
    /** The scene a missing or unknown value becomes, and the one a fresh install shows. */
    public const DEFAULT = 'doha';

    /** Logical px of one tile cell on a real page (web and app); the dashboard previews scale it down in proportion. */
    public const TILE_SIZE = 168;

    /** Folder of the drawings under public/. */
    public const FOLDER = 'brand';

    /**
     * Key => the generated file, whether it is a repeating tile, and the drawing's box. `doha` keeps its original file name.
     *
     * @var array<string, array{file: string, tile: bool, width: int, height: int}>
     */
    public const ALL = [
        'doha' => ['file' => 'qatar-skyline.svg', 'tile' => false, 'width' => 1600, 'height' => 340],
        'dhow' => ['file' => 'qatar-dhow.svg', 'tile' => false, 'width' => 1600, 'height' => 340],
        'souq' => ['file' => 'qatar-souq.svg', 'tile' => false, 'width' => 1600, 'height' => 340],
        'dunes' => ['file' => 'qatar-dunes.svg', 'tile' => false, 'width' => 1600, 'height' => 340],
        'museum' => ['file' => 'qatar-museum.svg', 'tile' => false, 'width' => 1600, 'height' => 340],
        'arabesque' => ['file' => 'qatar-arabesque.svg', 'tile' => true, 'width' => 200, 'height' => 200],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    public static function has(mixed $key): bool
    {
        return is_string($key) && isset(self::ALL[$key]);
    }

    /** Any value (missing, hand-edited, from an older server) becomes one of the six keys: unknown means the Doha skyline. */
    public static function normalize(mixed $key): string
    {
        return self::has($key) ? $key : self::DEFAULT;
    }

    public static function isTile(string $key): bool
    {
        return self::ALL[self::normalize($key)]['tile'];
    }

    /** File name of the drawing, e.g. `qatar-dhow.svg`. */
    public static function file(string $key): string
    {
        return self::ALL[self::normalize($key)]['file'];
    }

    /** Absolute path of the drawing on disk (for the tests that compare the generated copies). */
    public static function path(string $key): string
    {
        return public_path(self::FOLDER.'/'.self::file($key));
    }

    /** URL of the drawing, as the dashboard page paints it. */
    public static function url(string $key): string
    {
        return asset(self::FOLDER.'/'.self::file($key));
    }

    /** The artwork's name in English or Arabic (the locale of the request when none is given). */
    public static function name(string $key, ?string $locale = null): string
    {
        return __('backdrop_admin.scene_'.self::normalize($key), [], $locale);
    }

    /**
     * Everything the dashboard needs to draw the gallery and the previews.
     *
     * @return array<string, array{key: string, url: string, tile: bool, width: int, height: int}>
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::ALL as $key => $scene) {
            $out[$key] = ['key' => $key, 'url' => self::url($key), 'tile' => $scene['tile'], 'width' => $scene['width'], 'height' => $scene['height']];
        }

        return $out;
    }
}
