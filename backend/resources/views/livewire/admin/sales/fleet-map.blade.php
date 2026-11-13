<div class="pb-20">
    <x-admin.page-header :title="__('nav.fleet')" :eyebrow="__('nav.sales')" :description="__('fleet.hint')" />

    {{-- x-data is static on purpose: its value would change on every render, and Livewire would then rebuild the component (and the map). The start-up data
         rides on the wire:ignore element below, which Livewire never touches; later data arrives through the "fleet-updated" event. --}}
    <section class="card overflow-hidden" x-data="fleetMap" x-on:click.capture="openOrderLink($event)">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-sand-200 px-4 py-3 dark:border-ink-800">
            <select wire:model.live="branch" class="input max-w-56" aria-label="{{ __('fleet.all_branches') }}">
                <option value="">{{ __('fleet.all_branches') }}</option>
                @foreach ($branches as $b)<option value="{{ $b->id }}">{{ app()->getLocale() === 'ar' ? ($b->name_ar ?: $b->name_en) : $b->name_en }}</option>@endforeach
            </select>
        </div>
        {{-- The map owns everything inside this element (wire:ignore): Livewire never morphs it, and the kit replaces any earlier map on it. --}}
        <div wire:ignore x-ref="map" data-boot="{{ json_encode(['fleet' => $boot, 'live' => ['channels' => $liveChannels, 'text' => $liveText, 'orderUrl' => route('admin.orders.show', '__ID__')]]) }}" class="h-[72dvh] min-h-[480px] w-full bg-sand-100 lg:h-[calc(100dvh-20rem)] dark:bg-ink-800"></div>
    </section>

    @include('livewire.admin.sales.partials.order-peek')
</div>

@script
<script>
    Alpine.data('fleetMap', () => ({
        mount: null, toasts: null, liveOrders: { channels: [], text: {}, orderUrl: '' },
        async init() {
            const { fleet, live } = JSON.parse(this.$refs.map.dataset.boot);
            this.liveOrders = live;
            this.mount = await window.mountFleet(this.$refs.map, fleet, $wire);
            this.startLiveToasts();
        },
        /** A driver card's "open order" link opens the order in a side window above the map instead of leaving it (modified clicks keep the link). */
        openOrderLink(e) {
            const a = e.target.closest?.('a[href]');
            const id = a && !e.metaKey && !e.ctrlKey && !e.shiftKey && e.button === 0 ? (a.getAttribute('href').match(/\/admin\/orders\/([0-9a-f-]{36})(?:[?#]|$)/i) || [])[1] : null;
            if (!id) return;
            e.preventDefault();
            e.stopPropagation();
            $wire.peekOrder(id);
        },
        destroy() {
            this.mount?.destroy();
            this.mount = null;
            this.toasts?.destroy();
            this.toasts = null;
        },
        /** New orders and status changes from the staff live-orders channels, shown as toasts (de-duplicated across channels). */
        async startLiveToasts() {
            const liveOrders = this.liveOrders;
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
