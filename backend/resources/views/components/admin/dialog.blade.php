@props(['title' => null, 'description' => null, 'maxWidth' => 'max-w-lg', 'variant' => 'drawer'])
{{--
    Server-driven overlay: <x-admin.dialog wire:model="showEditor" ...>.

    variant="drawer" (default) — forms, details and quick views. Slides in from the END side (right in English, left in Arabic) and keeps the
        page in view; on phones it is a tall bottom sheet. The primary action row of a form is pinned to the bottom (see .drawer-body in app.css).
        An optional <x-slot:footer> is pinned too.
    variant="modal" — only for decisions ("Delete this?", "Rotate the link?"): centred, blocks everything, never closed by the scrim.

    Closing by ✕, Esc, scrim click or the browser/phone Back button closes exactly the top overlay and syncs back to Livewire (the stack lives in
    resources/js/overlays.js: Esc/Back/navigation, one history entry per window). Forms inside close it with `@click="requestClose()"` (asks "Discard your changes?" when the form holds unsaved input; a Save that closes it from the server does not ask).
    Width follows $maxWidth (max-w-md … max-w-5xl) so a wide editor gets a wide drawer. Focus moves in (first field, or Cancel in a decision) and
    returns to the button that opened it. Use wire:model.live for a record window whose id is kept in the URL (?view=).
--}}
@php
    $model = $attributes->wire('model')->value();
    $live = $attributes->wire('model')->hasModifier('live'); // wire:model.live: closing syncs at once (a ?view= record window clears its URL)
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
        open: $wire.entangle('{{ $model }}', {{ $live ? 'true' : 'false' }}),
        handle: null, dirty: false, submitted: false, asking: false, keeping: false,
        init() {
            this.$watch('open', (v) => {
                if (v) {
                    if (this.keeping) { this.keeping = false; } else { this.dirty = false; this.submitted = false; }
                    this.arm(false);
                    return;
                }
                if (this.guard()) { return; }
                this.disarm();
            });
            if (this.open) { this.arm(true); }
        },
        destroy() { this.disarm(); },
        arm(initial) {
            if (this.handle) { return; }
            this.handle = window.Overlays.open({ initial, dirty: () => this.dirty, close: (force) => this.requestClose(force) });
            setTimeout(() => this.focusIn(), 90);
        },
        disarm() {
            if (! this.handle) { return; }
            window.Overlays.release(this.handle);
            this.handle = null;
        },
        {{-- A user closing a window that holds unsaved input is asked first (✕, scrim, Esc, Back, Cancel); a Save that closes it is not. --}}
        requestClose(force) {
            if (force || {{ $modal ? 'true' : 'false' }} || ! this.dirty || window.Overlays.serverBusy()) { this.dirty = false; this.open = false; return; }
            if (this.handle && ! window.Overlays.isOpen(this.handle)) { this.handle = null; this.arm(false); }
            this.askDiscard();
        },
        guard() {
            if ({{ $modal ? 'true' : 'false' }} || ! this.dirty || window.Overlays.serverBusy()) { return false; }
            this.keeping = true;
            this.open = true;
            if (this.handle && ! window.Overlays.isOpen(this.handle)) { this.handle = null; this.arm(false); }
            this.askDiscard();
            return true;
        },
        askDiscard() {
            if (this.asking) { return; }
            this.asking = true;
            window.Overlays.confirm({ question: @js(__('drawer_admin.discard_q')), ok: @js(__('drawer_admin.discard')), cancel: @js(__('drawer_admin.keep_editing')), danger: true })
                .then((yes) => { this.asking = false; if (yes) { this.dirty = false; this.open = false; } });
        },
        markDirty(e) {
            if (e.target.closest && e.target.closest('[data-no-dirty], [type=search]')) { return; }
            this.dirty = true;
        },
        {{-- After a submit the form is clean unless the server answered with validation errors (then the typed input still needs protecting). --}}
        settled() {
            if (this.submitted && this.open) { this.dirty = !! this.$refs.panel.querySelector('.field-error, [role=alert]'); }
            this.submitted = false;
        },
        focusIn() {
            const root = this.$refs.panel;
            if (! this.open || ! root || root.contains(document.activeElement) && root !== document.activeElement && document.activeElement !== document.body) { return; }
            const touch = window.matchMedia('(pointer: coarse)').matches;
            const pick = root.querySelector('[autofocus]')
                || {{ $modal ? 'root.querySelector(\'[data-cancel], .btn-secondary\')' : '(touch ? null : root.querySelector(\'.drawer-body input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]):not([type=range]):not([disabled]), .drawer-body textarea:not([disabled]), .drawer-body select:not([disabled])\'))' }};
            (pick || root).focus({ preventScroll: true });
        },
     }"
     @lw-settled.window="settled()"
     x-cloak x-show="open"
     class="fixed inset-0 {{ $modal ? 'z-[72] flex items-end justify-center overflow-y-auto p-3 sm:items-center sm:p-6' : 'z-[68]' }}"
     role="{{ $modal ? 'alertdialog' : 'dialog' }}" aria-modal="true" @if($title) aria-label="{{ $title }}" @endif>
    @if ($modal)
        {{-- Decision: centred card; the scrim does not dismiss it. --}}
        <div x-show="open" x-transition.opacity class="fixed inset-0 bg-ink-950/60 backdrop-blur-sm"></div>
        <div x-ref="panel" tabindex="-1" x-show="open" x-transition x-trap.noscroll.noautofocus="open" class="card relative my-auto w-full {{ $maxWidth }} p-5 shadow-[var(--shadow-lift)] outline-none sm:p-7">
            @if ($title)
                <div class="mb-6 flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h2 class="font-display text-2xl font-semibold text-ink-900 dark:text-ivory">{{ $title }}</h2>
                        @if ($description)<p class="mt-1 text-sm text-ink-500 dark:text-ink-400">{{ $description }}</p>@endif
                    </div>
                    <button type="button" class="btn-ghost -me-2 -mt-1 size-11 p-0" @click="requestClose()" aria-label="{{ __('admin.close') }}"><x-icon name="x" class="size-4" /></button>
                </div>
            @endif
            {{ $slot }}
        </div>
    @else
        <div x-show="open" x-transition.opacity.duration.300ms class="absolute inset-0 bg-ink-950/45 backdrop-blur-[2px]" @click="requestClose()"></div>
        <section x-ref="panel" tabindex="-1"
                 x-show="open"
                 @input="markDirty($event)" @change="markDirty($event)" @submit="dirty = false; submitted = true"
                 @click="if ($event.target.closest && $event.target.closest('[data-saves]')) { dirty = false; submitted = true; }"
                 x-trap.noscroll.noautofocus="open"
                 x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="translate-y-full md:translate-y-0 md:ltr:translate-x-full md:rtl:-translate-x-full" x-transition:enter-end="translate-y-0 md:ltr:translate-x-0 md:rtl:translate-x-0"
                 x-transition:leave="transition duration-200 ease-in" x-transition:leave-start="translate-y-0 md:ltr:translate-x-0 md:rtl:translate-x-0" x-transition:leave-end="translate-y-full md:translate-y-0 md:ltr:translate-x-full md:rtl:-translate-x-full"
                 class="absolute inset-x-0 bottom-0 flex max-h-[92dvh] flex-col overflow-hidden rounded-t-3xl bg-ivory shadow-[var(--shadow-lift)] outline-none dark:bg-ink-900 md:inset-y-0 md:start-auto md:end-0 md:max-h-none {{ $width }} md:max-w-[calc(100vw-2.5rem)] md:rounded-none md:rounded-s-3xl">
            <div class="mx-auto mt-2 h-1.5 w-10 shrink-0 rounded-full bg-sand-300 dark:bg-ink-700 md:hidden" aria-hidden="true"></div>
            <header class="flex shrink-0 items-start justify-between gap-4 border-b border-sand-200/70 px-5 pb-4 pt-3 dark:border-ink-800 sm:px-6 md:pt-5">
                <div class="min-w-0">
                    @if ($title)<h2 class="font-display text-xl font-semibold leading-snug text-ink-900 dark:text-ivory sm:text-2xl">{{ $title }}</h2>@endif
                    @if ($description)<p class="mt-1 text-sm text-ink-500 dark:text-ink-400">{{ $description }}</p>@endif
                </div>
                <button type="button" class="btn-ghost -me-2 -mt-1 size-11 shrink-0 p-0" @click="requestClose()" aria-label="{{ __('admin.close') }}"><x-icon name="x" class="size-4" /></button>
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
