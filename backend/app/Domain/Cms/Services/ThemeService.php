<?php

namespace App\Domain\Cms\Services;

use App\Domain\Cms\Models\BrandTheme;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Chooses which look (colours, artwork, seasonal banner) the store and the apps show right now, and publishes it as
 * one small public payload. Order: a theme the admin activated by hand, then an enabled event whose dates match
 * today (highest priority wins), then the default theme.
 */
class ThemeService
{
    public const CACHE_KEY = 'brand_theme:active:v1';

    public function active(?CarbonImmutable $now = null): ?BrandTheme
    {
        $now ??= CarbonImmutable::now();

        return BrandTheme::where('is_forced', true)->first()
            ?? BrandTheme::where('is_enabled', true)->where('kind', '!=', 'base')->orderByDesc('priority')->get()
                ->first(fn (BrandTheme $t) => $this->matches($t, $now))
            ?? BrandTheme::where('is_default', true)->first();
    }

    /** True when now falls in the theme's one-off window or its yearly window, to the minute (Qatar time). */
    public function matches(BrandTheme $t, CarbonImmutable $now): bool
    {
        if ($t->starts_at || $t->ends_at) {
            return (! $t->starts_at || $t->starts_at->lte($now)) && (! $t->ends_at || $t->ends_at->gt($now));
        }
        if ($t->recur_start && $t->recur_end) {
            $nowKey = BusinessTime::local($now)->format('m-d H:i');
            $from = $t->recur_start.' '.($t->recur_start_time ?: '00:00');
            $to = $t->recur_end.' '.($t->recur_end_time ?: '23:59');

            return $from <= $to
                ? ($nowKey >= $from && $nowKey <= $to)
                : ($nowKey >= $from || $nowKey <= $to); // wraps over new year
        }

        return false;
    }

    /**
     * The window to show on a card: the one running now, else the next one coming (yearly looks: this year's or next year's).
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable, live: bool}|null
     */
    public function window(BrandTheme $t, ?CarbonImmutable $now = null): ?array
    {
        $now ??= CarbonImmutable::now();
        if ($t->starts_at || $t->ends_at) {
            $start = $t->starts_at ?? $now->subYears(1);
            $end = $t->ends_at ?? $now->addYears(1);

            return ['start' => $start, 'end' => $end, 'live' => $start->lte($now) && $end->gt($now)];
        }
        if (! $t->recur_start || ! $t->recur_end) {
            return null;
        }
        $zone = BusinessTime::zone();
        $year = (int) BusinessTime::local($now)->format('Y');
        $next = null;
        foreach ([$year - 1, $year, $year + 1] as $y) {
            $start = CarbonImmutable::parse("$y-{$t->recur_start} ".($t->recur_start_time ?: '00:00'), $zone);
            $endYear = ($t->recur_end.' '.($t->recur_end_time ?: '23:59')) < ($t->recur_start.' '.($t->recur_start_time ?: '00:00')) ? $y + 1 : $y;
            $end = CarbonImmutable::parse("$endYear-{$t->recur_end} ".($t->recur_end_time ?: '23:59'), $zone);
            if ($start->lte($now) && $end->gte($now)) {
                return ['start' => $start, 'end' => $end, 'live' => true];
            }
            $next ??= $start->gt($now) ? ['start' => $start, 'end' => $end, 'live' => false] : null;
        }

        return $next;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return Cache::remember(self::CACHE_KEY, 60, function () {
            $t = $this->active();

            return $t ? $this->present($t) : $this->fallback();
        });
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed> */
    public function present(BrandTheme $t): array
    {
        $url = fn (?string $p) => $p ? (str_starts_with($p, 'http') ? $p : Storage::disk(config('platform.media.disk'))->url($p)) : null;
        $w = $t->is_forced || $t->kind === 'base' ? null : $this->window($t);
        $nextChange = $w && $w['live'] ? $w['end']->toIso8601String() : null; // the apps refresh the look when this moment passes

        return [
            'key' => $t->key, 'kind' => $t->kind, 'name' => $t->localized('name'),
            'colors' => $t->colors + array_fill_keys(BrandTheme::COLOR_KEYS, null),
            'images' => ['hero' => $url($t->hero_image_path), 'banner' => $url($t->banner_image_path), 'logo' => $url($t->logo_path), 'splash' => $url($t->splash_image_path)],
            'banner' => ['title' => $t->localized('banner_title'), 'subtitle' => $t->localized('banner_subtitle')],
            'valid_until' => $nextChange, 'version' => md5($t->id.$t->updated_at?->toIso8601String()),
        ];
    }

    /** @return array<string, mixed> */
    private function fallback(): array
    {
        return ['key' => 'default', 'kind' => 'base', 'name' => 'Al Bahar', 'colors' => ['primary' => '#8A1538', 'primary_soft' => '#A3294E', 'accent' => '#B3254F', 'background' => '#FCF9FA', 'surface' => '#FFFFFF', 'ink' => '#2A1A20'],
            'images' => ['hero' => null, 'banner' => null, 'logo' => null, 'splash' => null], 'banner' => ['title' => null, 'subtitle' => null], 'valid_until' => null, 'version' => 'default'];
    }

    /** Switches a theme on by hand ("Activate now"); null returns to the schedule. */
    public function force(?BrandTheme $theme): void
    {
        DB::transaction(function () use ($theme) {
            BrandTheme::where('is_forced', true)->update(['is_forced' => false]);
            $theme?->update(['is_forced' => true]);
        });
        $this->forget();
    }

    /** Makes a theme the default (what shows outside event dates). */
    public function makeDefault(BrandTheme $theme): void
    {
        DB::transaction(function () use ($theme) {
            BrandTheme::where('is_default', true)->update(['is_default' => false]);
            $theme->update(['is_default' => true, 'is_enabled' => true]);
        });
        $this->forget();
    }

    /** Ready-made looks for Qatar's calendar. Idempotent: existing keys are left as the admin edited them. */
    public function seedPresets(): int
    {
        $n = 0;
        foreach ($this->presets() as $p) {
            $theme = BrandTheme::firstOrCreate(['key' => $p['key']], $p);
            $n += $theme->wasRecentlyCreated ? 1 : 0;
        }
        $this->forget();

        return $n;
    }

    /** Presets the admin has not added yet (or deleted), for the "Add a Qatar event" list. @return list<array<string, mixed>> */
    public function availablePresets(): array
    {
        $have = BrandTheme::pluck('key')->all();

        return array_values(array_filter($this->presets(), fn ($p) => ! in_array($p['key'], $have, true)));
    }

    public function addPreset(string $key): ?BrandTheme
    {
        $p = collect($this->presets())->firstWhere('key', $key);
        if (! $p || BrandTheme::where('key', $key)->exists()) {
            return null;
        }
        $this->forget();

        return BrandTheme::create($p);
    }

    /** @return list<array<string, mixed>> */
    public function presets(): array
    {
        $c = fn (string $p, string $soft, string $a, string $bg = '#FCF9FA', string $s = '#FFFFFF', string $ink = '#2A1A20') => ['primary' => $p, 'primary_soft' => $soft, 'accent' => $a, 'background' => $bg, 'surface' => $s, 'ink' => $ink];

        return [
            ['key' => 'qatar-maroon', 'kind' => 'base', 'name_en' => 'Qatar Maroon (default)', 'name_ar' => 'العنابي القطري (الافتراضي)', 'colors' => $c('#8A1538', '#A3294E', '#B3254F'), 'is_default' => true, 'is_enabled' => true, 'priority' => 0],
            ['key' => 'national-day', 'kind' => 'event', 'name_en' => 'Qatar National Day', 'name_ar' => 'اليوم الوطني لدولة قطر', 'colors' => $c('#8A1538', '#6E102D', '#C9A24D', '#FFFFFF'),
                'banner_title_en' => 'Happy Qatar National Day', 'banner_title_ar' => 'كل عام وقطر بخير في اليوم الوطني', 'banner_subtitle_en' => 'Celebrate 18 December with Al Bahar', 'banner_subtitle_ar' => 'احتفل بيوم ١٨ ديسمبر مع البحار',
                'recur_start' => '12-14', 'recur_end' => '12-19', 'is_enabled' => true, 'priority' => 200],
            ['key' => 'sports-day', 'kind' => 'event', 'name_en' => 'Qatar National Sport Day', 'name_ar' => 'اليوم الرياضي للدولة', 'colors' => $c('#8A1538', '#A3294E', '#F2A541', '#FFF9F5'),
                'banner_title_en' => 'National Sport Day', 'banner_title_ar' => 'اليوم الرياضي للدولة', 'banner_subtitle_en' => 'Move more, live better', 'banner_subtitle_ar' => 'تحرّك أكثر، عِش أفضل',
                'recur_start' => '02-08', 'recur_end' => '02-14', 'is_enabled' => false, 'priority' => 150],
            // Moon-sighting dates move every year: these windows are approximate, so they start switched off. The admin confirms the dates, then enables them.
            ['key' => 'ramadan', 'kind' => 'event', 'name_en' => 'Ramadan', 'name_ar' => 'رمضان', 'colors' => $c('#3B1C45', '#55306A', '#C9A24D', '#FBF8F3'),
                'banner_title_en' => 'Ramadan Kareem', 'banner_title_ar' => 'رمضان كريم', 'banner_subtitle_en' => 'Everything for a blessed month', 'banner_subtitle_ar' => 'كل ما تحتاجه لشهر مبارك',
                'starts_at' => CarbonImmutable::parse('2027-02-08 00:00', BusinessTime::zone())->utc(), 'ends_at' => CarbonImmutable::parse('2027-03-10 00:00', BusinessTime::zone())->utc(), 'is_enabled' => false, 'priority' => 180],
            ['key' => 'eid-al-fitr', 'kind' => 'event', 'name_en' => 'Eid Al Fitr', 'name_ar' => 'عيد الفطر', 'colors' => $c('#0F5C4D', '#1B7A67', '#C9A24D', '#FAFBF7'),
                'banner_title_en' => 'Eid Mubarak', 'banner_title_ar' => 'عيد مبارك', 'banner_subtitle_en' => 'Wishing you joy and blessings', 'banner_subtitle_ar' => 'نتمنى لكم أيام فرح وبركة',
                'starts_at' => CarbonImmutable::parse('2027-03-10 00:00', BusinessTime::zone())->utc(), 'ends_at' => CarbonImmutable::parse('2027-03-14 00:00', BusinessTime::zone())->utc(), 'is_enabled' => false, 'priority' => 190],
            ['key' => 'eid-al-adha', 'kind' => 'event', 'name_en' => 'Eid Al Adha', 'name_ar' => 'عيد الأضحى', 'colors' => $c('#0F5C4D', '#1B7A67', '#C9A24D', '#FAFBF7'),
                'banner_title_en' => 'Eid Al Adha Mubarak', 'banner_title_ar' => 'عيد أضحى مبارك', 'banner_subtitle_en' => 'Blessed Eid from Al Bahar', 'banner_subtitle_ar' => 'عيد مبارك من البحار',
                'starts_at' => CarbonImmutable::parse('2027-05-16 00:00', BusinessTime::zone())->utc(), 'ends_at' => CarbonImmutable::parse('2027-05-20 00:00', BusinessTime::zone())->utc(), 'is_enabled' => false, 'priority' => 190],
            // More of Qatar's calendar and shopping seasons. They start switched off: confirm the dates, then turn them on.
            ['key' => 'independence-day', 'kind' => 'event', 'name_en' => 'Qatar Independence Day', 'name_ar' => 'يوم الاستقلال', 'colors' => $c('#8A1538', '#6E102D', '#C9A24D', '#FFFFFF'),
                'banner_title_en' => 'Happy Independence Day', 'banner_title_ar' => 'كل عام وقطر بخير في يوم الاستقلال', 'banner_subtitle_en' => '3 September — proud to be Qatari', 'banner_subtitle_ar' => '٣ سبتمبر — فخورون بقطر',
                'recur_start' => '09-02', 'recur_end' => '09-04', 'is_enabled' => false, 'priority' => 170],
            ['key' => 'gargeean', 'kind' => 'event', 'name_en' => 'Gargee\'an', 'name_ar' => 'القرنقعوه', 'colors' => $c('#5B2A86', '#7A44A8', '#F2A541', '#FFF9F2'),
                'banner_title_en' => 'Happy Gargee\'an', 'banner_title_ar' => 'قرنقعوه مبارك', 'banner_subtitle_en' => 'Sweets, joy and tradition', 'banner_subtitle_ar' => 'حلوى وفرحة وتراث',
                'starts_at' => CarbonImmutable::parse('2027-02-20 12:00', BusinessTime::zone())->utc(), 'ends_at' => CarbonImmutable::parse('2027-02-22 00:00', BusinessTime::zone())->utc(), 'is_enabled' => false, 'priority' => 185],
            ['key' => 'hijri-new-year', 'kind' => 'event', 'name_en' => 'Hijri New Year', 'name_ar' => 'رأس السنة الهجرية', 'colors' => $c('#0F5C4D', '#1B7A67', '#C9A24D', '#FAFBF7'),
                'banner_title_en' => 'Happy Hijri New Year', 'banner_title_ar' => 'كل عام وأنتم بخير بالعام الهجري الجديد', 'banner_subtitle_en' => 'A blessed new year', 'banner_subtitle_ar' => 'عام مبارك',
                'starts_at' => CarbonImmutable::parse('2027-06-05 00:00', BusinessTime::zone())->utc(), 'ends_at' => CarbonImmutable::parse('2027-06-07 00:00', BusinessTime::zone())->utc(), 'is_enabled' => false, 'priority' => 160],
            ['key' => 'white-friday', 'kind' => 'event', 'name_en' => 'White Friday', 'name_ar' => 'الجمعة البيضاء', 'colors' => $c('#14161C', '#2B2E38', '#C9A24D', '#FFFFFF', '#FFFFFF', '#14161C'),
                'banner_title_en' => 'White Friday', 'banner_title_ar' => 'الجمعة البيضاء', 'banner_subtitle_en' => 'The biggest offers of the year', 'banner_subtitle_ar' => 'أكبر عروض العام',
                'recur_start' => '11-24', 'recur_end' => '11-30', 'is_enabled' => false, 'priority' => 175],
            ['key' => 'new-year', 'kind' => 'event', 'name_en' => 'New Year', 'name_ar' => 'رأس السنة الميلادية', 'colors' => $c('#14161C', '#2B2E38', '#C9A24D', '#FBF8F3'),
                'banner_title_en' => 'Happy New Year', 'banner_title_ar' => 'سنة جديدة سعيدة', 'banner_subtitle_en' => 'Start the year at home', 'banner_subtitle_ar' => 'ابدأ عامك في بيتك',
                'recur_start' => '12-31', 'recur_start_time' => '12:00', 'recur_end' => '01-02', 'recur_end_time' => '23:59', 'is_enabled' => false, 'priority' => 140],
            ['key' => 'back-to-school', 'kind' => 'event', 'name_en' => 'Back to School', 'name_ar' => 'العودة إلى المدارس', 'colors' => $c('#1F4E8C', '#3068AD', '#F2A541', '#F7FAFF'),
                'banner_title_en' => 'Back to school', 'banner_title_ar' => 'العودة إلى المدارس', 'banner_subtitle_en' => 'Desks, lamps and cosy corners', 'banner_subtitle_ar' => 'مكاتب وإضاءة وأركان مريحة',
                'recur_start' => '08-15', 'recur_end' => '09-05', 'is_enabled' => false, 'priority' => 120],
            ['key' => 'mothers-day', 'kind' => 'event', 'name_en' => 'Mother\'s Day', 'name_ar' => 'عيد الأم', 'colors' => $c('#A3294E', '#C2476D', '#F2A541', '#FFF8F9'),
                'banner_title_en' => 'Happy Mother\'s Day', 'banner_title_ar' => 'عيد أم سعيد', 'banner_subtitle_en' => 'Gifts for the heart of the home', 'banner_subtitle_ar' => 'هدايا لقلب البيت',
                'recur_start' => '03-19', 'recur_end' => '03-22', 'is_enabled' => false, 'priority' => 130],
        ];
    }
}
