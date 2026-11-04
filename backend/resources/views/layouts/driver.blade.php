@php
    $user = auth()->user();
    $rtl = in_array(app()->getLocale(), config('platform.rtl_locales'), true);
    $brand = app()->getLocale() === 'ar' ? config('platform.brand.name_ar') : config('platform.brand.name_en');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#14161c">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ $brand }}</title>
    <link rel="icon" href="/favicon.ico">
    <script>(function(){try{var s=localStorage.getItem('theme');if(s==='dark'||(!s&&matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.classList.add('dark')}catch(e){}})();</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-full bg-ivory dark:bg-ink-950">
<header class="sticky top-0 z-30 bg-ink-900 text-ivory shadow-lg pt-[env(safe-area-inset-top)]">
    <div class="mx-auto flex h-14 max-w-xl items-center gap-3 px-4">
        <div class="grid size-8 place-items-center rounded-lg bg-gradient-to-br from-brass-300 to-brass-600 font-display text-base font-semibold text-ink-950">{{ app()->getLocale() === 'ar' ? 'ب' : 'A' }}</div>
        <div class="min-w-0 flex-1">
            <div class="truncate text-sm font-semibold">{{ __('nav.driver') }}</div>
            <div class="truncate text-[0.7rem] text-ink-300">{{ $user->name }}</div>
        </div>
        <a href="{{ route('admin.locale', app()->getLocale() === 'ar' ? 'en' : 'ar') }}" class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-ink-200 hover:bg-white/10">{{ app()->getLocale() === 'ar' ? 'EN' : 'ع' }}</a>
        <form method="POST" action="{{ route('admin.logout') }}">@csrf<button class="rounded-lg p-2 text-ink-200 hover:bg-white/10" aria-label="{{ __('admin.logout') }}"><x-icon name="logout" class="size-4 rtl-flip" /></button></form>
    </div>
</header>
<main id="main" class="mx-auto max-w-xl px-4 pb-[calc(env(safe-area-inset-bottom)+2rem)] pt-5">
    {{ $slot }}
</main>
<div x-data="{ toasts: [] }"
     @toast.window="const t = { id: Date.now() + Math.random(), ...$event.detail }; toasts.push(t); setTimeout(() => toasts = toasts.filter(x => x.id !== t.id), 4500)"
     class="pointer-events-none fixed inset-x-4 bottom-4 z-[60] mx-auto flex max-w-md flex-col gap-2" aria-live="polite">
    <template x-for="t in toasts" :key="t.id">
        <div x-transition class="pointer-events-auto rounded-2xl px-4 py-3 text-sm font-medium shadow-[var(--shadow-lift)]"
             :class="t.type === 'error' ? 'bg-ruby-soft text-white' : (t.type === 'warning' ? 'bg-amber-soft text-white' : 'bg-ink-900 text-ivory')" x-text="t.message"></div>
    </template>
</div>
@livewireScripts
</body>
</html>
