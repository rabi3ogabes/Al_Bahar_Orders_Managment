<div {{ ($mode ?? '') === 'docked' ? 'data-docked-cart' : '' }} class="{{ ($mode ?? '') === 'docked' ? 'flex min-h-0 flex-1 flex-col' : '' }}">
@if (! $active)
@elseif ($mode === 'overlay')
    {{-- Drawer on tablets/desktops, bottom sheet on phones. Mounted only while open. Esc and the browser Back button close the top layer. --}}
    <div x-data="{
            shown: false, navigating: false,
            onPop() { if (this.discarding()) { return; } this.navigating = true; $wire.closePanel(); },
            discarding() { return !! $wire.discardId; },
            esc() { if ($wire.discardId) { $wire.cancelDiscard(); } else { $wire.closePanel(); } },
            init() { this.$nextTick(() => this.shown = true); history.pushState({ orderCart: true }, ''); this._pop = () => this.onPop(); window.addEventListener('popstate', this._pop); },
            destroy() { window.removeEventListener('popstate', this._pop); if (! this.navigating && history.state && history.state.orderCart) { history.back(); } },
        }"
         @keydown.escape.window="esc()"
         class="fixed inset-0 z-[65]" role="dialog" aria-modal="true" aria-label="{{ __('neworder_admin.panel_title') }}">
        <div x-show="shown" x-transition.opacity class="absolute inset-0 bg-ink-950/50 backdrop-blur-sm" @click="$wire.closePanel()"></div>
        <section x-show="shown" x-trap.noscroll="true" x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="translate-y-full md:translate-y-0 md:ltr:translate-x-full md:rtl:-translate-x-full"
                 class="absolute inset-x-0 bottom-0 flex max-h-[90dvh] flex-col overflow-hidden rounded-t-3xl bg-ivory shadow-[var(--shadow-lift)] dark:bg-ink-950 md:inset-y-0 md:start-auto md:end-0 md:max-h-none md:w-[28rem] md:rounded-none md:rounded-s-3xl">
            <div class="mx-auto mt-2 h-1.5 w-10 shrink-0 rounded-full bg-sand-300 dark:bg-ink-700 md:hidden" aria-hidden="true"></div>
            @include('livewire.admin.sales.order-cart-body')
        </section>
    </div>
@else
    @include('livewire.admin.sales.order-cart-body')
@endif
</div>
