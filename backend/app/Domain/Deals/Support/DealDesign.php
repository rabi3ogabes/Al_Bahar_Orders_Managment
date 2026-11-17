<?php

namespace App\Domain\Deals\Support;

use App\Domain\Cms\Services\ThemeService;
use App\Support\Contrast;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The look of a deal's countdown widget (docs/api/DEALS.md section 4): the same tokens in the dashboard preview, the web store and the apps.
 *
 * Everything is checked against fixed allow-lists; unknown keys are dropped. The SERVER resolves the colours: a response always carries all three
 * (the admin's own for `custom`, the preset's otherwise, the brand colour for `brand`), so no client invents a colour. Custom colours must be
 * readable: text on background and accent on background both need at least 3:1 (the dashboard warns below 4.5:1, WCAG AA).
 */
final class DealDesign
{
    public const STYLES = ['classic', 'bold', 'minimal', 'luxe', 'ribbon'];

    public const PALETTES = ['brand', 'gold', 'night', 'emerald', 'rose', 'custom'];

    public const SHAPES = ['rounded', 'sharp', 'pill'];

    public const LOOKS = ['boxes', 'digits', 'text'];

    public const UNITS = ['d', 'h', 'm', 's'];

    public const ICONS = ['flame', 'bolt', 'timer', 'tag', 'gift', 'star', 'crown'];

    /** Below this ratio the API refuses custom colours; the dashboard warns below WARN. */
    public const MIN_CONTRAST = 3.0;

    public const WARN_CONTRAST = Contrast::AA;

    public const MAX_URGENCY_MINUTES = 10080;

    public const DEFAULT_BRAND = '#8A1538';

    /** Ready-made colour sets (background, text, accent). `brand` is filled from the current look at read time. */
    public const PRESETS = [
        'gold' => ['background' => '#D4AF37', 'text' => '#241A00', 'accent' => '#6B1020'],
        'night' => ['background' => '#0F172A', 'text' => '#F8FAFC', 'accent' => '#FBBF24'],
        'emerald' => ['background' => '#065F46', 'text' => '#ECFDF5', 'accent' => '#FDE68A'],
        'rose' => ['background' => '#9F1239', 'text' => '#FFF1F2', 'accent' => '#FDE68A'],
    ];

    /** @return array<string, mixed> what a deal looks like when nothing is set */
    public static function defaults(): array
    {
        return [
            'style' => 'classic', 'palette' => 'brand', 'colors' => ['background' => '#0F172A', 'text' => '#F8FAFC', 'accent' => '#FBBF24'],
            'shape' => 'rounded', 'timer' => ['units' => self::UNITS, 'look' => 'boxes', 'urgency_minutes' => 60],
            'stock_bar' => true, 'percent_badge' => true, 'icon' => 'flame',
            'headline_en' => 'Ends in', 'headline_ar' => 'ينتهي خلال', 'cta_en' => 'Shop the deal', 'cta_ar' => 'تسوق العرض', 'image_path' => null,
        ];
    }

    /**
     * Strict check of a design: allow-lists, hex colours, readable contrast for a custom palette. Missing parts take the defaults, so an older or
     * partial design still validates. Returns the cleaned design (what is stored) or throws a ValidationException keyed `design.*`.
     *
     * @param  array<string, mixed>|null  $input
     * @return array<string, mixed>
     */
    public static function validate(?array $input): array
    {
        $input = array_replace_recursive(self::defaults(), self::only($input ?? []));
        // A list replaces the default list (array_replace_recursive would merge them by index).
        if (isset($input['timer']) && is_array($input['timer']) && is_array(($given = $input['timer']['units'] ?? null))) {
            $input['timer']['units'] = array_values($given);
        }
        $hex = ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'];
        $validator = Validator::make($input, [
            'style' => ['required', Rule::in(self::STYLES)],
            'palette' => ['required', Rule::in(self::PALETTES)],
            'colors' => ['required', 'array'],
            'colors.background' => $hex, 'colors.text' => $hex, 'colors.accent' => $hex,
            'shape' => ['required', Rule::in(self::SHAPES)],
            'timer' => ['required', 'array'],
            'timer.units' => ['required', 'array', 'min:2', 'max:4'],
            'timer.units.*' => ['string', Rule::in(self::UNITS), 'distinct'],
            'timer.look' => ['required', Rule::in(self::LOOKS)],
            'timer.urgency_minutes' => ['required', 'integer', 'min:0', 'max:'.self::MAX_URGENCY_MINUTES],
            'stock_bar' => ['required', 'boolean'], 'percent_badge' => ['required', 'boolean'],
            'icon' => ['required', Rule::in(self::ICONS)],
            'headline_en' => ['nullable', 'string', 'max:40'], 'headline_ar' => ['nullable', 'string', 'max:40'],
            'cta_en' => ['nullable', 'string', 'max:30'], 'cta_ar' => ['nullable', 'string', 'max:30'],
            'image_path' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', 'not_regex:/\.\./'],
        ]);
        $validator->after(function ($v) use ($input) {
            if ($v->errors()->isNotEmpty() || $input['palette'] !== 'custom') {
                return;
            }
            $c = $input['colors'];
            if (Contrast::ratio($c['text'], $c['background']) < self::MIN_CONTRAST) {
                $v->errors()->add('colors.text', __('deals.err_contrast_text'));
            }
            if (Contrast::ratio($c['accent'], $c['background']) < self::MIN_CONTRAST) {
                $v->errors()->add('colors.accent', __('deals.err_contrast_accent'));
            }
        });
        try {
            $data = $validator->validate();
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $key) => ['design.'.$key => $messages])->all());
        }

        $words = fn (string $key) => ($t = trim(strip_tags((string) ($data[$key] ?? '')))) === '' ? self::defaults()[$key] : $t;

        return [
            'style' => $data['style'], 'palette' => $data['palette'],
            // The custom colours are kept even while a preset is chosen, so switching back to Custom in the dashboard finds them again.
            'colors' => ['background' => strtoupper($data['colors']['background']), 'text' => strtoupper($data['colors']['text']), 'accent' => strtoupper($data['colors']['accent'])],
            'shape' => $data['shape'],
            'timer' => ['units' => self::canonicalUnits($data['timer']['units']), 'look' => $data['timer']['look'], 'urgency_minutes' => (int) $data['timer']['urgency_minutes']],
            'stock_bar' => (bool) $data['stock_bar'], 'percent_badge' => (bool) $data['percent_badge'], 'icon' => $data['icon'],
            'headline_en' => $words('headline_en'), 'headline_ar' => $words('headline_ar'), 'cta_en' => $words('cta_en'), 'cta_ar' => $words('cta_ar'),
            'image_path' => ($data['image_path'] ?? null) ?: null,
        ];
    }

    /**
     * What clients receive: the stored design with the colours always filled and the image as a URL. A stored design that is no longer valid
     * (edited by hand in the database) falls back to the defaults rather than breaking the page.
     *
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    public static function resolve(?array $stored, ?string $brand = null): array
    {
        try {
            $design = self::validate($stored);
        } catch (ValidationException) {
            $design = self::validate(null);
        }
        $design['colors'] = self::colors($design, $brand);
        $path = $design['image_path'];
        unset($design['image_path']);
        $design['image_url'] = $path ? (str_starts_with($path, 'http') ? null : Storage::disk(config('platform.media.disk'))->url($path)) : null;

        return $design;
    }

    /**
     * The three colours the design really uses.
     *
     * @param  array<string, mixed>  $design  a validated design
     * @return array{background: string, text: string, accent: string}
     */
    public static function colors(array $design, ?string $brand = null): array
    {
        $palette = (string) $design['palette'];
        if ($palette === 'custom') {
            return $design['colors'];
        }
        if ($palette !== 'brand') {
            return self::PRESETS[$palette];
        }
        $bg = Contrast::isHex($brand) ? strtoupper((string) $brand) : self::brandPrimary();
        $text = Contrast::ratio('#FFFFFF', $bg) >= Contrast::ratio('#1B1B1B', $bg) ? '#FFFFFF' : '#1B1B1B';
        $accent = collect(['#F5C451', '#B91C1C', '#FFFFFF', '#1B1B1B'])->first(fn (string $c) => Contrast::ratio($c, $bg) >= self::WARN_CONTRAST && $c !== $text) ?? $text;

        return ['background' => $bg, 'text' => $text, 'accent' => $accent];
    }

    /** The main colour of the look that is live now (cached by the theme service), or the original maroon. */
    public static function brandPrimary(): string
    {
        $primary = app(ThemeService::class)->payload()['colors']['primary'] ?? null;

        return Contrast::isHex($primary) ? strtoupper($primary) : self::DEFAULT_BRAND;
    }

    /**
     * A plain-words warning for the dashboard when the colours are legal but hard to read (below WCAG AA), else null.
     *
     * @param  array<string, mixed>  $design  a validated design
     */
    public static function contrastWarning(array $design): ?string
    {
        $c = self::colors($design);
        if (Contrast::ratio($c['text'], $c['background']) < self::WARN_CONTRAST || Contrast::ratio($c['accent'], $c['background']) < self::WARN_CONTRAST) {
            return __('deals.warn_contrast');
        }

        return null;
    }

    /** @param  list<string>  $units */
    private static function canonicalUnits(array $units): array
    {
        return array_values(array_filter(self::UNITS, fn (string $u) => in_array($u, $units, true)));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function only(array $input): array
    {
        $known = array_intersect_key($input, self::defaults());
        foreach (['colors', 'timer'] as $group) {
            if (isset($known[$group]) && ! is_array($known[$group])) {
                unset($known[$group]);
            } elseif (isset($known[$group])) {
                $known[$group] = array_intersect_key($known[$group], self::defaults()[$group]);
            }
        }

        return $known;
    }
}
