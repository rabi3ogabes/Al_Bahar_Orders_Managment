@php
    $loc = app()->getLocale();
    $bName = fn ($b) => $loc === 'ar' ? ($b->name_ar ?: $b->name_en) : $b->name_en;
    $payload = [
        'drivers' => $fleet->values(),
        'branches' => $branches->filter(fn ($b) => $b->latitude !== null)->map(fn ($b) => ['id' => $b->id, 'name' => $bName($b), 'lat' => (float) $b->latitude, 'lng' => (float) $b->longitude])->values(),
    ];
    $statusLabels = ['assigned' => __('fleet.st_assigned'), 'accepted' => __('fleet.st_accepted'), 'picked_up' => __('fleet.st_picked_up'), 'out_for_delivery' => __('fleet.st_out_for_delivery')];
@endphp
<div wire:poll.15s>
    <x-admin.page-header :title="__('nav.fleet')" :eyebrow="__('nav.sales')" :description="__('fleet.hint')" />

    <div class="grid gap-6 lg:grid-cols-3 [&>*]:min-w-0"
         x-data="fleetMap(@js($payload), @js($statusLabels), @js($channels), @js(['offline' => __('fleet.offline'), 'idle' => __('fleet.idle'), 'job' => __('fleet.job'), 'seen' => __('fleet.seen')]))"
         x-init="init()">
        <section class="card overflow-hidden lg:col-span-2">
            <div class="flex items-center justify-between gap-3 border-b border-sand-200 px-4 py-3 dark:border-ink-800">
                <select wire:model.live="branch" class="input max-w-56" aria-label="{{ __('fleet.all_branches') }}">
                    <option value="">{{ __('fleet.all_branches') }}</option>
                    @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $bName($b) }}</option>@endforeach
                </select>
                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs" :class="live ? 'bg-emerald-soft/10 text-emerald-soft' : 'bg-sand-100 text-ink-500 dark:bg-ink-800'">
                    <span class="size-1.5 rounded-full bg-current" :class="live && 'animate-pulse'"></span>
                    <span x-text="live ? @js(__('auction_admin.realtime_on')) : @js(__('auction_admin.realtime_polling'))"></span>
                </span>
            </div>
            <div wire:ignore x-ref="map" class="h-[60vh] min-h-[360px] w-full bg-sand-100 dark:bg-ink-800" role="application" aria-label="{{ __('nav.fleet') }}"></div>
        </section>

        <aside class="card min-w-0 self-start">
            <h2 class="border-b border-sand-200 px-4 py-3 font-display text-lg font-semibold text-ink-900 dark:border-ink-800 dark:text-ivory">{{ __('fleet.drivers') }} <span class="text-sm font-normal text-ink-500">({{ $fleet->count() }})</span></h2>
            @forelse ($fleet as $d)
                <button type="button" @click="focus('{{ $d['id'] }}')" class="flex min-h-11 w-full items-start gap-3 border-b border-sand-100 px-4 py-3 text-start last:border-0 hover:bg-sand-50 dark:border-ink-800 dark:hover:bg-ink-800/50">
                    <span class="mt-1 size-2.5 shrink-0 rounded-full {{ $d['on_duty'] ? ($d['open_jobs'] ? 'bg-brass-500' : 'bg-emerald-soft') : 'bg-ink-300' }}"></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium text-ink-900 dark:text-ivory">{{ $d['name'] }}</span>
                        <span class="block truncate text-xs text-ink-500">
                            @if ($d['job']) {{ __('fleet.job', ['n' => $d['job']['order_number']]) }} · {{ $statusLabels[$d['job']['status']] ?? $d['job']['status'] }}
                            @else {{ $d['on_duty'] ? __('fleet.idle') : __('fleet.offline') }} @endif
                        </span>
                        <span class="block truncate text-xs text-ink-400">{{ collect($d['branches'])->join(' · ') }}</span>
                    </span>
                </button>
            @empty
                <p class="px-4 py-10 text-center text-sm text-ink-500">{{ __('fleet.empty') }}</p>
            @endforelse
        </aside>
    </div>
</div>

@script
<script>
    Alpine.data('fleetMap', (initial, statuses, channels, t) => ({
        live: false, L: null, map: null, markers: {}, data: initial,
        async init() {
            this.L = await window.loadLeaflet();
            const L = this.L;
            this.map = L.map(this.$refs.map, { zoomControl: true, attributionControl: true }).setView([25.2854, 51.531], 11);
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(this.map);
            (initial.branches || []).forEach(b => L.marker([b.lat, b.lng], { icon: this.icon('#8A1538', '🏬') }).addTo(this.map).bindTooltip(b.name));
            this.draw(initial.drivers || [], true);
            $wire.on('fleet-updated', (e) => this.draw((e.drivers ?? e[0]?.drivers) || [], false));
            const e = window.echo && window.echo();
            if (e) {
                channels.forEach((c) => e.private(c).listen('.driver.moved', (m) => this.moved(m)));
                e.connector?.pusher?.connection?.bind('state_change', (s) => this.live = s.current === 'connected');
                this.live = e.connector?.pusher?.connection?.state === 'connected';
                document.addEventListener('livewire:navigating', () => channels.forEach((c) => e.leave(c)), { once: true });
            }
        },
        icon(color, glyph) {
            return this.L.divIcon({ className: '', iconSize: [34, 34], iconAnchor: [17, 17],
                html: `<div style="width:34px;height:34px;border-radius:50%;background:${color};color:#fff;display:flex;align-items:center;justify-content:center;font-size:16px;box-shadow:0 2px 8px rgba(0,0,0,.35);border:2px solid #fff">${glyph}</div>` });
        },
        draw(drivers, fit) {
            const L = this.L, seen = new Set(), pts = [];
            drivers.forEach(d => {
                if (d.lat == null) return;
                seen.add(d.id); pts.push([d.lat, d.lng]);
                const color = d.open_jobs ? '#B8893A' : (d.on_duty ? '#2E7D5B' : '#9AA0A6');
                const label = `<strong>${d.name}</strong><br>${d.job ? t.job.replace(':n', d.job.order_number) + ' · ' + (statuses[d.job.status] || d.job.status) : (d.on_duty ? t.idle : t.offline)}`;
                if (this.markers[d.id]) { this.markers[d.id].setLatLng([d.lat, d.lng]).setIcon(this.icon(color, '🛵')).setTooltipContent(label); }
                else { this.markers[d.id] = L.marker([d.lat, d.lng], { icon: this.icon(color, '🛵') }).addTo(this.map).bindTooltip(label); }
            });
            Object.keys(this.markers).forEach(id => { if (!seen.has(id)) { this.map.removeLayer(this.markers[id]); delete this.markers[id]; } });
            if (fit && pts.length) this.map.fitBounds(pts, { padding: [48, 48], maxZoom: 15 });
        },
        moved(m) { const mk = this.markers[m.driver_id]; if (mk) mk.setLatLng([m.lat, m.lng]); else $wire.$refresh(); },
        focus(id) { const mk = this.markers[id]; if (mk) { this.map.setView(mk.getLatLng(), 16); mk.openTooltip(); } },
    }));
</script>
@endscript
