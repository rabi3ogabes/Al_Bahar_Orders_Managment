@props(['look', 'colors', 'name', 'tagline', 'ends', 'percent' => null, 'left' => null, 'leftPercent' => null, 'price' => null, 'imageUrl' => null, 'skew' => 0])
{{--
    The dashboard's drawing of a deal widget (docs/api/DEALS.md section 4): the same tokens the store and the app draw from. It counts down from `$ends`
    (an ISO time), shows both languages (the owner toggles EN / AR and light / dark around it), and follows reduced motion (no pulse when the person asks for none).
    $look is a validated DealDesign; $colors the three resolved colours; $skew = browser clock minus server clock, in ms.
--}}
@php
    $style = $look['style'];
    $shape = $look['shape'];
    $timer = $look['timer'];
    $units = $timer['units'];
    $round = match ($shape) { 'sharp' => 'rounded-none', 'pill' => 'rounded-[2.25rem]', default => 'rounded-3xl' };
    $inner = match ($shape) { 'sharp' => 'rounded-none', 'pill' => 'rounded-full', default => 'rounded-xl' };
    $pad = $style === 'minimal' ? 'p-3' : 'p-4 sm:p-5';
    $border = match ($style) { 'luxe' => 'border-2', 'minimal' => 'border', default => 'border-0' };
    $face = $style === 'luxe' ? 'font-display tracking-wide' : '';
    $digits = $style === 'bold' ? 'text-3xl sm:text-4xl font-black' : ($style === 'minimal' ? 'text-lg font-bold' : 'text-2xl font-bold');
    $gradient = $style === 'luxe' ? "linear-gradient(145deg, {$colors['background']}, color-mix(in srgb, {$colors['background']} 70%, black))" : $colors['background'];
    $labels = ['d' => ['en' => 'Days', 'ar' => 'يوم'], 'h' => ['en' => 'Hours', 'ar' => 'ساعة'], 'm' => ['en' => 'Min', 'ar' => 'دقيقة'], 's' => ['en' => 'Sec', 'ar' => 'ثانية']];
    $urgent = (int) $timer['urgency_minutes'];
@endphp
<div x-data="{
        lang: 'en', dark: false, now: Date.now(), t: null,
        init() { this.t = setInterval(() => { this.now = Date.now(); }, 1000); },
        destroy() { clearInterval(this.t); },
        get end() { return Date.parse(this.$root.dataset.end) || (Date.now() + 86400000); },
        get left() { return Math.max(0, Math.floor((this.end - this.now + {{ (int) $skew }}) / 1000)); },
        get urgent() { return {{ $urgent }} > 0 && this.left < {{ $urgent }} * 60; },
        part(u) { const l = this.left; return u === 'd' ? Math.floor(l / 86400) : u === 'h' ? Math.floor((l % 86400) / 3600) : u === 'm' ? Math.floor((l % 3600) / 60) : l % 60; },
        pad(n) { return String(n).padStart(2, '0'); },
        shown(u) { return u === 'd' ? this.left >= 86400 : true; },
     }" data-end="{{ $ends }}" {{ $attributes->class(['space-y-3']) }}>
    <style>
        @media (prefers-reduced-motion: no-preference) { .deal-urgent-pulse { animation: deal-urgent 1s ease-in-out infinite; } }
        @keyframes deal-urgent { 0%, 100% { opacity: 1; } 50% { opacity: .55; } }
    </style>
    <div class="flex flex-wrap items-center gap-2 text-xs" data-no-dirty>
        <div class="inline-flex rounded-full bg-sand-100 p-0.5 dark:bg-ink-800" role="group" aria-label="{{ __('deals_admin.preview_language') }}">
            @foreach (['en' => 'EN', 'ar' => 'ع'] as $code => $text)
                <button type="button" @click="lang = '{{ $code }}'" :aria-pressed="lang === '{{ $code }}'" class="min-h-9 min-w-11 rounded-full px-3 font-medium transition" :class="lang === '{{ $code }}' ? 'bg-ink-900 text-ivory dark:bg-brass-500 dark:text-ink-950' : 'text-ink-600 dark:text-ink-300'">{{ $text }}</button>
            @endforeach
        </div>
        <div class="inline-flex rounded-full bg-sand-100 p-0.5 dark:bg-ink-800" role="group" aria-label="{{ __('deals_admin.preview_theme') }}">
            <button type="button" @click="dark = false" :aria-pressed="! dark" class="inline-flex min-h-9 min-w-11 items-center justify-center rounded-full px-3 transition" :class="! dark ? 'bg-ink-900 text-ivory dark:bg-brass-500 dark:text-ink-950' : 'text-ink-600 dark:text-ink-300'" aria-label="{{ __('deals_admin.preview_light') }}"><x-icon name="sun" class="size-4" /></button>
            <button type="button" @click="dark = true" :aria-pressed="dark" class="inline-flex min-h-9 min-w-11 items-center justify-center rounded-full px-3 transition" :class="dark ? 'bg-ink-900 text-ivory dark:bg-brass-500 dark:text-ink-950' : 'text-ink-600 dark:text-ink-300'" aria-label="{{ __('deals_admin.preview_dark') }}"><x-icon name="moon" class="size-4" /></button>
        </div>
    </div>

    {{-- The surface the widget sits on in the store or the app. --}}
    <div class="overflow-hidden rounded-2xl border border-sand-200 p-3 transition-colors dark:border-ink-700" :class="dark ? 'bg-ink-950' : 'bg-white'" :dir="lang === 'ar' ? 'rtl' : 'ltr'">
        <div class="relative isolate overflow-hidden {{ $round }} {{ $border }} {{ $face }} {{ $pad }}"
             style="background: {{ $gradient }}; color: {{ $colors['text'] }}; border-color: {{ $style === 'luxe' ? $colors['accent'] : 'color-mix(in srgb, '.$colors['text'].' 25%, transparent)' }}">
            @if ($imageUrl)
                <img src="{{ $imageUrl }}" alt="" class="absolute inset-0 -z-10 size-full object-cover">
                <span class="absolute inset-0 -z-10" style="background: linear-gradient(180deg, color-mix(in srgb, {{ $colors['background'] }} 55%, transparent), color-mix(in srgb, {{ $colors['background'] }} 88%, transparent))"></span>
            @endif
            @if ($style === 'ribbon' && $look['percent_badge'] && $percent)
                <span class="absolute -end-9 top-4 w-32 rotate-45 py-1 text-center text-xs font-extrabold rtl:-rotate-45" style="background: {{ $colors['accent'] }}; color: {{ $colors['background'] }}">-{{ $percent }}%</span>
            @endif
            @if ($style === 'bold')<span class="absolute inset-x-0 top-0 h-1.5" style="background: {{ $colors['accent'] }}"></span>@endif

            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider" style="color: {{ $colors['accent'] }}">
                        <x-icon :name="['flame' => 'flame', 'bolt' => 'zap', 'timer' => 'timer', 'tag' => 'tag', 'gift' => 'gift', 'star' => 'star', 'crown' => 'crown'][$look['icon']] ?? 'flame'" class="size-4" />
                        <span x-show="lang === 'en'">{{ $look['headline_en'] }}</span><span x-show="lang === 'ar'" x-cloak>{{ $look['headline_ar'] }}</span>
                    </p>
                    <p class="mt-1 truncate text-base font-bold sm:text-lg">
                        <span x-show="lang === 'en'">{{ $name['en'] ?: __('deals_admin.sample_name') }}</span><span x-show="lang === 'ar'" x-cloak>{{ $name['ar'] ?: $name['en'] ?: __('deals_admin.sample_name') }}</span>
                    </p>
                    @if ($tagline['en'] || $tagline['ar'])
                        <p class="truncate text-xs opacity-80"><span x-show="lang === 'en'">{{ $tagline['en'] ?: $tagline['ar'] }}</span><span x-show="lang === 'ar'" x-cloak>{{ $tagline['ar'] ?: $tagline['en'] }}</span></p>
                    @endif
                </div>
                @if ($look['percent_badge'] && $percent && $style !== 'ribbon')
                    <span class="shrink-0 px-2.5 py-1 text-sm font-extrabold {{ $shape === 'sharp' ? 'rounded-none' : 'rounded-full' }}" style="background: {{ $colors['accent'] }}; color: {{ $colors['background'] }}">-{{ $percent }}%</span>
                @endif
            </div>

            {{-- The countdown: boxes, big digits or plain words; days appear only while 24 hours or more are left. --}}
            <div class="mt-3 flex flex-wrap items-center gap-2" role="timer" aria-live="off">
                @foreach ($units as $u)
                    @if ($timer['look'] === 'boxes')
                        <div x-show="shown('{{ $u }}')" class="min-w-[3.6rem] flex-1 px-2 py-2 text-center {{ $inner }}" style="background: color-mix(in srgb, {{ $colors['text'] }} 14%, transparent)">
                            <p class="tabular-nums leading-none {{ $digits }}" :class="urgent ? 'deal-urgent-pulse' : ''" :style="urgent ? 'color: {{ $colors['accent'] }}' : ''" x-text="pad(part('{{ $u }}'))">00</p>
                            <p class="mt-1 text-[0.65rem] uppercase tracking-wide opacity-80"><span x-show="lang === 'en'">{{ $labels[$u]['en'] }}</span><span x-show="lang === 'ar'" x-cloak>{{ $labels[$u]['ar'] }}</span></p>
                        </div>
                    @elseif ($timer['look'] === 'digits')
                        <span x-show="shown('{{ $u }}')" class="tabular-nums {{ $digits }}" :class="urgent ? 'deal-urgent-pulse' : ''" :style="urgent ? 'color: {{ $colors['accent'] }}' : ''"><span x-text="pad(part('{{ $u }}'))">00</span>@if (! $loop->last)<span class="px-0.5 opacity-60">:</span>@endif</span>
                    @else
                        <span x-show="shown('{{ $u }}')" class="text-sm font-semibold tabular-nums" :class="urgent ? 'deal-urgent-pulse' : ''" :style="urgent ? 'color: {{ $colors['accent'] }}' : ''"><span x-text="part('{{ $u }}')">0</span> <span class="font-normal opacity-80"><span x-show="lang === 'en'">{{ strtolower($labels[$u]['en']) }}</span><span x-show="lang === 'ar'" x-cloak>{{ $labels[$u]['ar'] }}</span></span></span>
                    @endif
                @endforeach
            </div>

            @if ($price)
                <p class="mt-3 flex items-baseline gap-2"><span class="text-lg font-extrabold tabular-nums" style="color: {{ $colors['accent'] }}" dir="ltr">{{ $price['deal'] }}</span><span class="text-xs line-through opacity-70 tabular-nums" dir="ltr">{{ $price['normal'] }}</span></p>
            @endif

            @if ($look['stock_bar'] && $left !== null)
                <div class="mt-3">
                    <div class="h-2 overflow-hidden {{ $shape === 'sharp' ? 'rounded-none' : 'rounded-full' }}" style="background: color-mix(in srgb, {{ $colors['text'] }} 20%, transparent)"><div class="h-full" style="width: {{ max(4, min(100, (int) $leftPercent)) }}%; background: {{ $colors['accent'] }}"></div></div>
                    <p class="mt-1 text-xs font-medium"><span x-show="lang === 'en'">{{ __('deals_admin.only_left', ['n' => $left], 'en') }}</span><span x-show="lang === 'ar'" x-cloak>{{ __('deals_admin.only_left', ['n' => $left], 'ar') }}</span></p>
                </div>
            @endif

            <p class="mt-4 inline-flex min-h-11 items-center justify-center px-5 text-sm font-bold {{ $shape === 'sharp' ? 'rounded-none' : ($shape === 'pill' ? 'rounded-full' : 'rounded-xl') }}" style="background: {{ $colors['accent'] }}; color: {{ $colors['background'] }}">
                <span x-show="lang === 'en'">{{ $look['cta_en'] }}</span><span x-show="lang === 'ar'" x-cloak>{{ $look['cta_ar'] }}</span>
            </p>
        </div>
    </div>
</div>
