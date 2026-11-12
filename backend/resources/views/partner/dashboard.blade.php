@extends('partner.layout')
@section('body')
<div id="app" class="mx-auto max-w-[1500px] px-4 pb-24 pt-5 sm:px-6">
    <header class="mb-5 flex flex-wrap items-center gap-3">
        <div class="flex items-center gap-3">
            <div class="grid size-10 place-items-center rounded-xl bg-gradient-to-br from-brass-300 to-brass-600 font-display text-xl font-semibold text-ink-950" aria-hidden="true">A</div>
            <div>
                <h1 class="font-display text-2xl font-semibold leading-tight" data-i18n="title">Partner statistics</h1>
                <p class="text-xs text-ink-500"><span class="inline-flex items-center gap-1.5"><span class="size-1.5 animate-pulse rounded-full bg-emerald-500"></span><span data-i18n="live">Live</span></span> · <span id="updated"></span></p>
            </div>
        </div>
        <div class="ms-auto flex flex-wrap items-center gap-2">
            <div class="flex items-center overflow-hidden rounded-xl border border-sand-300 bg-white dark:border-ink-600 dark:bg-ink-800" role="group" aria-label="Date">
                <button id="prev" class="grid size-11 place-items-center hover:bg-sand-100 dark:hover:bg-ink-700" aria-label="Previous day"><span aria-hidden="true">‹</span></button>
                <input id="date" type="date" class="h-11 border-0 bg-transparent px-2 text-sm focus:ring-0" aria-label="Date">
                <button id="next" class="grid size-11 place-items-center hover:bg-sand-100 dark:hover:bg-ink-700" aria-label="Next day"><span aria-hidden="true">›</span></button>
            </div>
            <button id="today" class="btn-secondary h-11" data-i18n="today">Today</button>
            <div class="flex overflow-hidden rounded-xl border border-sand-300 dark:border-ink-600" role="group" aria-label="View">
                <button id="v-table" class="h-11 px-3.5 text-sm" aria-pressed="true" data-i18n="table">Table</button>
                <button id="v-grid" class="h-11 px-3.5 text-sm" aria-pressed="false" data-i18n="grid">Grid</button>
            </div>
            <button id="lang" class="btn-ghost h-11" type="button">العربية</button>
            <button id="signout" class="btn-ghost h-11" type="button" data-i18n="signout">Lock</button>
        </div>
    </header>

    <section id="kpis" class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6" aria-live="polite"></section>

    <div class="grid gap-5 xl:grid-cols-[1fr_380px]">
        <section class="min-w-0">
            <div id="orders" class="min-w-0"></div>
        </section>
        <aside class="space-y-5">
            <section class="card overflow-hidden">
                <div class="flex items-center justify-between border-b border-sand-200 px-4 py-3 dark:border-ink-700">
                    <h2 class="font-display text-lg font-semibold" data-i18n="drivers_live">Drivers, live</h2>
                    <span id="fleet-count" class="text-xs text-ink-500"></span>
                </div>
                <div id="fleet-map" class="h-72 w-full bg-sand-100 dark:bg-ink-800" role="application" aria-label="Live driver map"></div>
                <ul id="fleet-list" class="divide-y divide-sand-100 dark:divide-ink-700"></ul>
            </section>
        </aside>
    </div>
</div>

<div id="scrim" class="fixed inset-0 z-30 hidden bg-black/40"></div>
<aside id="drawer" role="dialog" aria-modal="true" aria-labelledby="drawer-title" tabindex="-1"
       class="fixed inset-x-0 bottom-0 z-40 hidden max-h-[92dvh] translate-y-full flex-col rounded-t-3xl bg-white shadow-2xl transition-transform duration-300 dark:bg-ink-900 md:inset-y-0 md:end-0 md:start-auto md:max-h-none md:w-[640px] md:translate-y-0 md:rounded-none md:rounded-s-3xl">
</aside>
<div id="toast" class="pointer-events-none fixed inset-x-0 bottom-4 z-50 flex justify-center"></div>
@endsection
