@php
    $user = auth()->user();
    $rtl = in_array(app()->getLocale(), config('platform.rtl_locales'), true);
    $nav = \App\Support\AdminNavigation::for($user);
    $canOrder = app(\App\Domain\Identity\Services\AccessControl::class)->allows($user, 'orders.create');
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
    <link rel="icon" href="/favicon.ico">
    <script>/* avoid theme flash */ (function(){try{var s=localStorage.getItem('theme');if(s==='dark'||(!s&&matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.classList.add('dark')}catch(e){}})();</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full" x-data="{ sidebar: false }" @keydown.escape="sidebar = false">
<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:start-3 focus:z-50 btn-primary">{{ __('admin.skip_to_content') }}</a>

{{-- Mobile overlay --}}
<div x-cloak x-show="sidebar" x-transition.opacity class="fixed inset-0 z-40 bg-ink-950/60 backdrop-blur-sm lg:hidden" @click="sidebar = false"></div>

{{-- Sidebar --}}
<aside class="fixed inset-y-0 start-0 z-50 flex w-72 flex-col bg-ink-900 text-ink-300 transition-transform duration-300 lg:translate-x-0"
       :class="sidebar ? 'translate-x-0' : '{{ $rtl ? 'translate-x-full' : '-translate-x-full' }}'"
       aria-label="{{ __('admin.main_navigation') }}">
    <div class="flex h-20 items-center gap-3 px-6">
        <div class="grid size-10 place-items-center rounded-xl bg-gradient-to-br from-brass-300 to-brass-600 font-display text-xl font-semibold text-ink-950 shadow-lg shadow-brass-900/30">
            {{ app()->getLocale() === 'ar' ? 'ب' : 'A' }}
        </div>
        <div class="min-w-0">
            <div class="truncate font-display text-lg font-semibold leading-tight text-ivory">{{ $brand }}</div>
            <div class="text-[0.68rem] uppercase tracking-[0.2em] text-brass-300">{{ __('admin.console') }}</div>
        </div>
    </div>
    <nav class="flex-1 space-y-6 overflow-y-auto px-4 pb-6 [scrollbar-width:thin]">
        @php
            // Active item: exact route, or a child route (e.g. admin.orders.show) of an ".index" item when no nav item matches exactly.
            $navRoutes = collect($nav)->flatMap(fn ($g) => array_column($g['items'], 'route'));
            $current = request()->route()?->getName();
            $exactInNav = $navRoutes->contains($current);
        @endphp
        @foreach ($nav as $group)
            <div>
                <div class="mb-2 px-3 text-[0.65rem] font-semibold uppercase tracking-[0.18em] text-ink-500">{{ __($group['label']) }}</div>
                <ul class="space-y-0.5">
                    @foreach ($group['items'] as $item)
                        @php $active = $current === $item['route'] || (! $exactInNav && str_ends_with($item['route'], '.index') && request()->routeIs(\Illuminate\Support\Str::beforeLast($item['route'], '.').'.*')); @endphp
                        <li>
                            <a href="{{ route($item['route']) }}" wire:navigate class="nav-link {{ $active ? 'nav-link-active' : '' }}" @if($active) aria-current="page" @endif>
                                <x-icon :name="$item['icon']" class="size-[1.15rem] shrink-0 {{ $active ? 'text-brass-300' : '' }}" />
                                <span class="truncate">{{ __($item['label']) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>
    @if ($canOrder)
        <div class="px-4 pb-2"><livewire:admin.sales.order-cart-button variant="sidebar" lazy /></div>
    @endif
    <div class="border-t border-white/5 p-4">
        <a href="{{ route('admin.profile') }}" wire:navigate class="flex items-center gap-3 rounded-xl p-2 transition hover:bg-white/5">
            <div class="grid size-9 place-items-center rounded-full bg-ink-700 text-sm font-semibold text-sand-100">{{ mb_substr($user->name, 0, 1) }}</div>
            <div class="min-w-0 flex-1">
                <div class="truncate text-sm font-medium text-ivory">{{ $user->name }}</div>
                <div class="truncate text-xs text-ink-400">{{ $user->job_title ?: ($user->isSuperAdmin() ? __('admin.super_admin') : __('admin.staff')) }}</div>
            </div>
        </a>
    </div>
</aside>

<div class="lg:ps-72">
    {{-- Top bar --}}
    <header class="sticky top-0 z-30 border-b border-sand-200/60 bg-ivory/85 backdrop-blur-md dark:border-ink-800 dark:bg-ink-950/85">
        <div class="flex h-16 items-center gap-3 px-4 sm:px-6 lg:px-10">
            <button type="button" class="btn-ghost -ms-2 lg:hidden" @click="sidebar = true" aria-label="{{ __('admin.open_menu') }}"><x-icon name="menu" /></button>
            <div class="min-w-0 flex-1">
                @isset($breadcrumb)<div class="truncate text-xs text-ink-500">{{ $breadcrumb }}</div>@endisset
            </div>
            @if ($canOrder)<livewire:admin.sales.order-cart-button variant="header" lazy />@endif
            <a href="{{ route('admin.locale', app()->getLocale() === 'ar' ? 'en' : 'ar') }}" class="btn-ghost btn-sm" title="{{ __('admin.switch_language') }}">
                <x-icon name="globe" class="size-4" /><span class="font-medium">{{ app()->getLocale() === 'ar' ? 'English' : 'العربية' }}</span>
            </a>
            <button type="button" onclick="toggleTheme()" class="btn-ghost btn-sm" aria-label="{{ __('admin.toggle_theme') }}">
                <x-icon name="moon" class="size-4 dark:hidden" /><x-icon name="sun" class="hidden size-4 dark:block" />
            </button>
            <form method="POST" action="{{ route('admin.logout') }}">
                @csrf
                <button class="btn-ghost btn-sm" aria-label="{{ __('admin.logout') }}"><x-icon name="logout" class="size-4 rtl-flip" /><span class="hidden sm:inline">{{ __('admin.logout') }}</span></button>
            </form>
        </div>
    </header>

    <main id="main" class="px-4 py-8 sm:px-6 lg:px-10 lg:py-10">
        {{ $slot }}
    </main>
</div>

{{-- Order cart overlay (admin-assisted orders): opened from the header/sidebar button, anywhere in the dashboard. The New order page docks its own. --}}
@if ($canOrder && ! request()->routeIs('admin.orders.create'))
    <livewire:admin.sales.order-cart mode="overlay" />
@endif

{{-- Toasts: $this->dispatch('toast', type: 'success', message: '...') — add undoLabel, undoEvent, undoParams for an Undo button --}}
<div x-data="{ toasts: [] }"
     @toast.window="const t = { id: Date.now() + Math.random(), ...$event.detail }; toasts.push(t); setTimeout(() => toasts = toasts.filter(x => x.id !== t.id), t.undoEvent ? 9000 : 4500)"
     class="pointer-events-none fixed bottom-6 end-6 z-[60] flex w-[min(24rem,calc(100vw-3rem))] flex-col gap-3" aria-live="polite">
    @if (session('toast'))
        <div x-init="$dispatch('toast', @js(session('toast')))"></div>
    @endif
    <template x-for="t in toasts" :key="t.id">
        <div x-transition class="pointer-events-auto card flex items-start gap-3 p-4 shadow-[var(--shadow-lift)]">
            <span class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-full"
                  :class="t.type === 'error' ? 'bg-ruby-soft/10 text-ruby-soft' : (t.type === 'warning' ? 'bg-amber-soft/10 text-amber-soft' : 'bg-emerald-soft/10 text-emerald-soft')">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path x-show="t.type !== 'error'" d="M20 6L9 17l-5-5"/><path x-show="t.type === 'error'" d="M18 6L6 18M6 6l12 12"/></svg>
            </span>
            <p class="min-w-0 flex-1 text-sm text-ink-800 dark:text-sand-100" x-text="t.message"></p>
            <template x-if="t.undoEvent">
                <button type="button" class="-my-2 -me-2 min-h-11 shrink-0 rounded-lg px-3 text-sm font-semibold text-brass-700 hover:bg-sand-100 focus-visible:outline-2 focus-visible:outline-offset-2 dark:text-brass-300 dark:hover:bg-ink-800"
                        @click="Livewire.dispatch(t.undoEvent, t.undoParams || {}); toasts = toasts.filter(x => x.id !== t.id)" x-text="t.undoLabel"></button>
            </template>
        </div>
    </template>
</div>
@livewireScripts
</body>
</html>
