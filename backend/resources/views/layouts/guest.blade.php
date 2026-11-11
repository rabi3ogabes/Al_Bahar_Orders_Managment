@php
    $rtl = in_array(app()->getLocale(), config('platform.rtl_locales'), true);
    $brand = app()->getLocale() === 'ar' ? config('platform.brand.name_ar') : config('platform.brand.name_en');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ $brand }}</title>
    <script>(function(){try{var s=localStorage.getItem('theme');if(s==='dark'||(!s&&matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.classList.add('dark')}catch(e){}})();</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full">
<div class="grid min-h-full lg:grid-cols-[1.1fr_1fr]">
    <section class="relative hidden overflow-hidden bg-ink-900 lg:block" aria-hidden="true">
        <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_left,rgba(194,159,102,0.28),transparent_55%),radial-gradient(ellipse_at_bottom_right,rgba(194,159,102,0.12),transparent_50%)]"></div>
        <svg class="absolute -bottom-24 -end-24 size-[34rem] text-brass-500/10" viewBox="0 0 200 200" fill="none" stroke="currentColor" stroke-width="0.6">
            @for ($i = 0; $i < 9; $i++)<circle cx="100" cy="100" r="{{ 20 + $i * 10 }}"/>@endfor
        </svg>
        <div class="relative flex h-full flex-col justify-between p-14 text-ivory">
            <div class="flex items-center gap-3">
                <div class="grid size-11 place-items-center rounded-xl bg-gradient-to-br from-brass-300 to-brass-600 font-display text-2xl font-semibold text-ink-950">{{ app()->getLocale() === 'ar' ? 'ب' : 'A' }}</div>
                <span class="font-display text-2xl">{{ $brand }}</span>
            </div>
            <div class="max-w-md">
                <p class="eyebrow mb-5 !text-brass-300">{{ __('admin.console') }}</p>
                <h1 class="font-display text-5xl leading-[1.08] font-medium">{{ __('admin.hero_title') }}</h1>
                <p class="mt-6 text-base leading-relaxed text-ink-300">{{ __('admin.hero_body') }}</p>
            </div>
            <p class="text-xs text-ink-500">© {{ now()->year }} {{ $brand }} · {{ __('admin.doha_qatar') }}</p>
        </div>
    </section>
    <section class="flex items-center justify-center px-6 py-12 sm:px-12">
        <div class="w-full max-w-sm">
            <div class="mb-10 flex items-center justify-between">
                <div class="flex items-center gap-2 lg:hidden">
                    <div class="grid size-9 place-items-center rounded-lg bg-gradient-to-br from-brass-300 to-brass-600 font-display text-lg font-semibold text-ink-950">{{ app()->getLocale() === 'ar' ? 'ب' : 'A' }}</div>
                    <span class="font-display text-lg">{{ $brand }}</span>
                </div>
                <a href="{{ route('admin.locale', app()->getLocale() === 'ar' ? 'en' : 'ar') }}" class="btn-ghost btn-sm ms-auto"><x-icon name="globe" class="size-4" />{{ app()->getLocale() === 'ar' ? 'English' : 'العربية' }}</a>
            </div>
            {{ $slot }}
        </div>
    </section>
</div>
@livewireScripts
</body>
</html>
