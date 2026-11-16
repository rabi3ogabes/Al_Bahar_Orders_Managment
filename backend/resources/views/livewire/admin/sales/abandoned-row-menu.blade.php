{{-- Row actions: a popover under the button on tablets and desktops, a bottom sheet on phones (Back, Esc and the scrim close it). Needs $row and $canChange. --}}
@php $rowName = $row->user?->name ?? __('abandoned_admin.unknown_customer'); @endphp
<div x-data="{
        o: false, sheet: false, style: '', handle: null,
        toggle() {
            if (this.o) { return this.close(); }
            this.sheet = ! window.matchMedia('(min-width: 768px)').matches;
            const r = this.$refs.btn.getBoundingClientRect();
            const rtl = getComputedStyle(document.documentElement).direction === 'rtl';
            const up = r.bottom + 200 > window.innerHeight;
            this.style = (up ? 'bottom:' + (window.innerHeight - r.top + 6) + 'px;' : 'top:' + (r.bottom + 6) + 'px;') + (rtl ? 'left:' + Math.max(8, r.left) + 'px' : 'right:' + Math.max(8, window.innerWidth - r.right) + 'px');
            this.o = true;
            if (this.sheet) { this.handle = window.Overlays.open({ close: () => this.close() }); }
            this.$nextTick(() => this.$refs.first && this.$refs.first.focus());
        },
        close() {
            if (! this.o) { return; }
            this.o = false;
            if (this.handle !== null) { window.Overlays.release(this.handle); this.handle = null; }
            this.$refs.btn.focus({ preventScroll: true });
        },
        run(fn) { this.close(); setTimeout(fn, 0); },
        step(d) {
            const items = [...this.$refs.panel.querySelectorAll('[role=menuitem]:not([disabled])')];
            const i = items.indexOf(document.activeElement);
            (items[(i + d + items.length) % items.length] || items[0]).focus();
        },
     }"
     @click.stop @click.outside="close()" @keydown.escape.stop="close()" class="inline-flex">
    <button type="button" x-ref="btn" @click="toggle()" :aria-expanded="o" aria-haspopup="menu" class="btn-ghost size-11 !p-0" aria-label="{{ __('abandoned_admin.actions_for', ['name' => $rowName]) }}">
        <x-icon name="more" class="size-5" />
    </button>
    <div x-show="o" x-cloak>
        <div x-show="sheet" x-transition.opacity class="fixed inset-0 z-[66] bg-ink-950/50" @click="close()" aria-hidden="true"></div>
        <div x-ref="panel" role="menu" aria-label="{{ __('abandoned_admin.actions_for', ['name' => $rowName]) }}" :style="sheet ? '' : style" x-trap.noscroll="o && sheet"
             @keydown.arrow-down.prevent="step(1)" @keydown.arrow-up.prevent="step(-1)"
             x-transition:enter="transition duration-150 ease-out" x-transition:enter-start="opacity-0 max-md:translate-y-full md:scale-95"
             class="fixed z-[67] border-sand-200 bg-white p-2 shadow-[var(--shadow-lift)] dark:border-ink-700 dark:bg-ink-900 max-md:inset-x-0 max-md:bottom-0 max-md:rounded-t-3xl max-md:border-t max-md:pb-[max(1rem,env(safe-area-inset-bottom))] md:w-56 md:rounded-xl md:border">
            <div class="mx-auto mb-1 mt-1 h-1.5 w-10 rounded-full bg-sand-300 dark:bg-ink-700 md:hidden" aria-hidden="true"></div>
            <p class="truncate px-3 pb-1 pt-1 text-sm font-semibold text-ink-900 dark:text-ivory md:hidden">{{ $rowName }}</p>
            <button type="button" role="menuitem" x-ref="first" @click="run(() => $wire.openCart('{{ $row->id }}'))" class="flex min-h-11 w-full items-center gap-3 rounded-lg px-3 text-start text-sm hover:bg-sand-100 focus:bg-sand-100 focus:outline-none dark:hover:bg-ink-800 dark:focus:bg-ink-800">
                <x-icon name="eye" class="size-4 text-ink-500" />{{ __('abandoned_admin.open') }}
            </button>
            @if ($canChange)
                <button type="button" role="menuitem" @click="run(() => $wire.remind('{{ $row->id }}'))" class="flex min-h-11 w-full items-center gap-3 rounded-lg px-3 text-start text-sm hover:bg-sand-100 focus:bg-sand-100 focus:outline-none dark:hover:bg-ink-800 dark:focus:bg-ink-800">
                    <x-icon name="send" class="size-4 text-ink-500" />{{ __('abandoned_admin.send_reminder') }}
                </button>
                <div class="my-1 h-px bg-sand-100 dark:bg-ink-800" role="separator"></div>
                <button type="button" role="menuitem" @click="run(() => $wire.discard('{{ $row->id }}'))" class="flex min-h-11 w-full items-center gap-3 rounded-lg px-3 text-start text-sm text-ruby-soft hover:bg-ruby-soft/10 focus:bg-ruby-soft/10 focus:outline-none">
                    <x-icon name="trash" class="size-4" />{{ __('abandoned_admin.discard') }}
                </button>
            @endif
        </div>
    </div>
</div>
