@props(['title' => null, 'description' => null, 'maxWidth' => 'max-w-lg', 'variant' => 'drawer'])
{{--
    Server-driven overlay: <x-admin.dialog wire:model="showEditor" ...>.

    variant="drawer" (default) — forms, details and quick views. Slides in from the END side (right in English, left in Arabic) and keeps the
        page in view; on phones it is a tall bottom sheet. The primary action row of a form is pinned to the bottom (see .drawer-body in app.css).
        An optional <x-slot:footer> is pinned too.
    variant="modal" — only for decisions ("Delete this?", "Rotate the link?"): centred, blocks everything, never closed by the scrim.

    Closing by ✕, Esc, scrim click or the browser/phone Back button closes exactly the top overlay and syncs back to Livewire. Forms inside can
    close it with `@click="open = false"`. Width follows $maxWidth (max-w-md … max-w-5xl) so a wide editor gets a wide drawer.
--}}
@php
    $model = $attributes->wire('model')->value();
    $modal = $variant === 'modal';
    $width = match ($maxWidth) {
        'max-w-xs', 'max-w-sm', 'max-w-md' => 'md:w-[26rem]',
        'max-w-lg' => 'md:w-[30rem]',
        'max-w-xl' => 'md:w-[34rem]',
        'max-w-2xl' => 'md:w-[40rem]',
        'max-w-3xl' => 'md:w-[48rem]',
        'max-w-4xl' => 'md:w-[56rem]',
        default => 'md:w-[64rem]',
    };
@endphp
<div x-data="{
        open: $wire.entangle('{{ $model }}'),
        depth: 0, pushed: false, id: Math.random().toString(36).slice(2),
        init() {
            window.__ovStack = window.__ovStack || [];
            this.$watch('open', (v) => v ? this.arm() : this.disarm());
            this._pop = () => {
                if (! this.pushed) { return; }
                if (((history.state && history.state.__ov) || 0) < this.depth) { this.pushed = false; this.open = false; }
            };
            window.addEventListener('popstate', this._pop);
            if (this.open) { this.arm(); }
        },
        destroy() { window.removeEventListener('popstate', this._pop); this.disarm(); },
        isTop() { return window.__ovStack[window.__ovStack.length - 1] === this.id; },
        esc() { if (this.open && this.isTop()) { this.open = false; } },
        arm() {
            if (! window.__ovStack.includes(this.id)) { window.__ovStack.push(this.id); }
            this.depth = ((history.state && history.state.__ov) || 0) + 1;
            history.pushState({ ...(history.state || {}), __ov: this.depth }, '');
            this.pushed = true;
        },
        disarm() {
            window.__ovStack = (window.__ovStack || []).filter((x) => x !== this.id);
            if (this.pushed) {
                this.pushed = false;
                if (history.state && history.state.__ov === this.depth) { history.back(); }
            }
        },
     }"
     x-on:keydown.escape.window="esc()"
     x-cloak x-show="open"
     class="fixed inset-0 {{ $modal ? 'z-[72] flex items-end justify-center overflow-y-auto p-3 sm:items-center sm:p-6' : 'z-[68]' }}"
     role="{{ $modal ? 'alertdialog' : 'dialog' }}" aria-modal="true" @if($title) aria-label="{{ $title }}" @endif>
    @if ($modal)
        {{-- Decision: centred card; the scrim does not dismiss it. --}}
        <div x-show="open" x-transition.opacity class="fixed inset-0 bg-ink-950/60 backdrop-blur-sm"></div>
        <div x-show="open" x-transition class="card relative my-auto w-full {{ $maxWidth }} p-5 shadow-[var(--shadow-lift)] sm:p-7" x-trap.noscroll="open">
            @if ($title)
                <div class="mb-6 flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h2 class="font-display text-2xl font-semibold text-ink-900 dark:text-ivory">{{ $title }}</h2>
                        @if ($description)<p class="mt-1 text-sm text-ink-500 dark:text-ink-400">{{ $description }}</p>@endif
                    </div>
                    <button type="button" class="btn-ghost -me-2 -mt-1 size-11 p-0" @click="open = false" aria-label="{{ __('admin.close') }}"><x-icon name="x" class="size-4" /></button>
                </div>
            @endif
            {{ $slot }}
        </div>
    @else
        <div x-show="open" x-transition.opacity.duration.300ms class="absolute inset-0 bg-ink-950/45 backdrop-blur-[2px]" @click="open = false"></div>
        <section x-show="open"
                 x-trap.noscroll="open"
                 x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="translate-y-full md:translate-y-0 md:ltr:translate-x-full md:rtl:-translate-x-full" x-transition:enter-end="translate-y-0 md:ltr:translate-x-0 md:rtl:translate-x-0"
                 x-transition:leave="transition duration-200 ease-in" x-transition:leave-start="translate-y-0 md:ltr:translate-x-0 md:rtl:translate-x-0" x-transition:leave-end="translate-y-full md:translate-y-0 md:ltr:translate-x-full md:rtl:-translate-x-full"
                 class="absolute inset-x-0 bottom-0 flex max-h-[92dvh] flex-col overflow-hidden rounded-t-3xl bg-ivory shadow-[var(--shadow-lift)] dark:bg-ink-900 md:inset-y-0 md:start-auto md:end-0 md:max-h-none {{ $width }} md:max-w-[calc(100vw-2.5rem)] md:rounded-none md:rounded-s-3xl">
            <div class="mx-auto mt-2 h-1.5 w-10 shrink-0 rounded-full bg-sand-300 dark:bg-ink-700 md:hidden" aria-hidden="true"></div>
            <header class="flex shrink-0 items-start justify-between gap-4 border-b border-sand-200/70 px-5 pb-4 pt-3 dark:border-ink-800 sm:px-6 md:pt-5">
                <div class="min-w-0">
                    @if ($title)<h2 class="font-display text-xl font-semibold leading-snug text-ink-900 dark:text-ivory sm:text-2xl">{{ $title }}</h2>@endif
                    @if ($description)<p class="mt-1 text-sm text-ink-500 dark:text-ink-400">{{ $description }}</p>@endif
                </div>
                <button type="button" class="btn-ghost -me-2 -mt-1 size-11 shrink-0 p-0" @click="open = false" aria-label="{{ __('admin.close') }}"><x-icon name="x" class="size-4" /></button>
            </header>
            <div class="drawer-body min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 pt-5 sm:px-6">
                {{ $slot }}
            </div>
            @isset($footer)
                <footer class="shrink-0 border-t border-sand-200/70 bg-ivory px-5 py-4 pb-[max(1rem,env(safe-area-inset-bottom))] dark:border-ink-800 dark:bg-ink-900 sm:px-6">{{ $footer }}</footer>
            @endisset
        </section>
    @endif
</div>
