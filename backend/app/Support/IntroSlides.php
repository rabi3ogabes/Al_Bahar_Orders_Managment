<?php

namespace App\Support;

use App\Domain\Settings\Services\Settings;
use App\Rules\IntroText;

/**
 * The words of the customer app's three intro slides, editable in Settings → General & brand → "Intro screens".
 *
 * Twelve plain-text settings, `customer_app.intro_<slide>_<title|body>_<en|ar>` (slide 1..3). A stored text is the admin's own;
 * an empty one means "use the app's shipped text", which is why `GET /api/v1/app-config` sends `null` for it and never a copy of
 * the default (the app has its own, already translated and tested; see `config/platform.php` → `customer_app.intro_defaults`).
 *
 * Everything that reaches a customer goes through {@see self::clean()}, whoever wrote it, so a text can never carry markup, line
 * breaks, control characters or more than the slide has room for.
 */
final class IntroSlides
{
    public const SLIDES = 3;

    /** Characters (not bytes): what fits one or two lines under the picture on a 320 dp phone with larger text. */
    public const TITLE_MAX = 40;

    public const BODY_MAX = 120;

    public const LANGS = ['en', 'ar'];

    public const PARTS = ['title', 'body'];

    public static function key(int $slide, string $part, string $lang): string
    {
        return "customer_app.intro_{$slide}_{$part}_{$lang}";
    }

    /** The settings-page field name of a key (the same `.` → `__` rule as the rest of the page). */
    public static function field(int $slide, string $part, string $lang): string
    {
        return str_replace('.', '__', self::key($slide, $part, $lang));
    }

    public static function max(string $part): int
    {
        return $part === 'title' ? self::TITLE_MAX : self::BODY_MAX;
    }

    /** @return list<int> */
    public static function slideNumbers(): array
    {
        return range(1, self::SLIDES);
    }

    /**
     * The settings-page definitions of all twelve fields, in reading order (flagged `intro` so the generic field list skips them and
     * the "Intro screens" block draws them instead).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function schema(): array
    {
        $out = [];
        foreach (self::slideNumbers() as $slide) {
            foreach (self::PARTS as $part) {
                foreach (self::LANGS as $lang) {
                    $out[self::key($slide, $part, $lang)] = [
                        'type' => 'text',
                        'rules' => ['nullable', 'string', 'max:'.self::max($part), new IntroText],
                        'dir' => $lang === 'ar' ? 'rtl' : 'ltr',
                        'intro' => true,
                    ];
                }
            }
        }

        return $out;
    }

    /** The shipped text of one slide part in one language (the dashboard's placeholder and preview), from config/platform.php. */
    public static function default(int $slide, string $part, string $lang): string
    {
        return (string) config("platform.customer_app.intro_defaults.{$slide}.{$part}.{$lang}", '');
    }

    /**
     * Plain text, one line: every kind of white space (new lines, tabs, no-break spaces) turned into one space, the ends trimmed, tags
     * removed. Text that is not valid UTF-8 cannot be shown, so it becomes empty. Control characters are NOT removed: the dashboard refuses
     * them ({@see self::hasControlCharacters()}) and the API drops the whole text when one slipped in, so a text that has one is returned
     * as it is (strip_tags would quietly eat a NUL byte and hide the problem).
     */
    public static function clean(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            return '';
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (self::hasControlCharacters($text)) {
            return $text;
        }

        return trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));
    }

    /** True for control characters (C0, DEL, C1) and the bidirectional overrides that can reorder what a customer reads. */
    public static function hasControlCharacters(string $text): bool
    {
        return preg_match('/[\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $text) === 1;
    }

    /**
     * What `GET /app-config` serves for one stored text: the cleaned text clamped to the limit, or null ("use the app's own").
     * Stored values come from the dashboard (already cleaned and checked) but also from seeders, imports and older builds: nothing
     * unexpected may reach a phone.
     */
    public static function served(mixed $value, string $part): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $text = self::clean($value);
        if ($text === '' || self::hasControlCharacters($text)) {
            return null;
        }

        return rtrim(mb_substr($text, 0, self::max($part)));
    }

    /**
     * The `intro` object of `GET /app-config`: three slides, each `{title:{en,ar}, body:{en,ar}}`, a text being the admin's or null.
     *
     * @return array{slides: list<array<string, array<string, string|null>>>}
     */
    public static function forApi(Settings $settings): array
    {
        $slides = [];
        foreach (self::slideNumbers() as $slide) {
            $row = [];
            foreach (self::PARTS as $part) {
                foreach (self::LANGS as $lang) {
                    $row[$part][$lang] = self::served($settings->get(self::key($slide, $part, $lang)), $part);
                }
            }
            $slides[] = $row;
        }

        return ['slides' => $slides];
    }
}
