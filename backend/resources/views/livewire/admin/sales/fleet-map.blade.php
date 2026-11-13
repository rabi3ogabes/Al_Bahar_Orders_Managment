<div>
    <x-admin.page-header :title="__('nav.fleet')" :eyebrow="__('nav.sales')" :description="__('fleet.hint')" />

    <section class="card overflow-hidden"
             x-data="fleetMap(@js($boot), @js(['channels' => $liveChannels, 'text' => $liveText, 'orderUrl' => route('admin.orders.show', '__ID__')]))">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-sand-200 px-4 py-3 dark:border-ink-800">
            <select wire:model.live="branch" class="input max-w-56" aria-label="{{ __('fleet.all_branches') }}">
                <option value="">{{ __('fleet.all_branches') }}</option>
                @foreach ($branches as $b)<option value="{{ $b->id }}">{{ app()->getLocale() === 'ar' ? ($b->name_ar ?: $b->name_en) : $b->name_en }}</option>@endforeach
            </select>
        </div>
        {{-- The map owns everything inside this element (wire:ignore): Livewire never morphs it, and the kit replaces any earlier map on it. --}}
        <div wire:ignore x-ref="map" class="h-[72dvh] min-h-[480px] w-full bg-sand-100 lg:h-[calc(100dvh-17rem)] dark:bg-ink-800"></div>
    </section>
</div>

@script
<script>
    Alpine.data('fleetMap', (boot, liveOrders) => ({
        mount: null, toasts: null,
        async init() {
            this.mount = await window.mountFleet(this.$refs.map, boot, $wire);
            this.startLiveToasts();
        },
        destroy() {
            this.mount?.destroy();
            this.mount = null;
            this.toasts?.destroy();
            this.toasts = null;
        },
        /** New orders and status changes from the staff live-orders channels, shown as toasts (de-duplicated across channels). */
        async startLiveToasts() {
            const e = window.echo && window.echo();
            if (!e || !liveOrders.channels.length) return;
            const { createLiveToasts } = await window.loadLiveToasts();
            this.toasts = createLiveToasts({
                t: (k, v) => (liveOrders.text[k] ?? k).replace(/:(\w+)/g, (m, n) => (v && n in v ? v[n] : m)),
                href: (ev) => liveOrders.orderUrl.replace('__ID__', encodeURIComponent(ev.orderId)),
                navigate: true,
            });
            liveOrders.channels.forEach((c) => e.private(c).listen('.order.live', (m) => this.toasts?.push(m)));
            document.addEventListener('livewire:navigating', () => { liveOrders.channels.forEach((c) => e.leave(c)); this.toasts?.destroy(); this.toasts = null; }, { once: true });
        },
    }));
</script>
@endscript
