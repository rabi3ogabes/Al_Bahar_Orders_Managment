<div>
    <x-admin.page-header :title="$greeting.', '.\Illuminate\Support\Str::before(auth()->user()->name, ' ')" :eyebrow="\App\Support\BusinessTime::now()->locale(app()->getLocale())->translatedFormat('l, d F Y')" :description="__('admin.dashboard_hint')">
        <x-slot:actions>
            @if ($branches->count() > 1)
                <select wire:model.live="branchId" class="input w-auto min-w-52" aria-label="{{ __('admin.branch') }}">
                    <option value="">{{ __('admin.all_my_branches') }}</option>
                    @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->localized('name') }}</option>@endforeach
                </select>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
        <x-admin.stat :label="__('admin.revenue_today')" :value="$m['revenue_today']" icon="wallet" tone="brass" />
        <x-admin.stat :label="__('admin.orders_today')" :value="number_format($m['orders_today'])" icon="bag" />
        <x-admin.stat :label="__('admin.to_prepare')" :value="number_format($m['pending_fulfillments'])" icon="package" :hint="__('admin.ready_pickup_count', ['count' => $m['ready_for_pickup']])" />
        <x-admin.stat :label="__('admin.low_stock')" :value="number_format($m['low_stock'])" icon="alert" :hint="__('admin.out_of_stock_count', ['count' => $m['out_of_stock']])" />
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-3">
        <div class="card card-pad xl:col-span-2">
            <div class="flex items-center justify-between">
                <div><p class="eyebrow">{{ __('admin.last_14_days') }}</p><h2 class="mt-1 font-display text-2xl font-semibold">{{ __('admin.sales_overview') }}</h2></div>
            </div>
            <div class="mt-6 h-72" wire:ignore x-data="{ chart: null }"
                 x-init="
                    const css = getComputedStyle(document.documentElement);
                    chart = new Chart($refs.c, {
                        type: 'bar',
                        data: { labels: @js($m['chart']['labels']), datasets: [
                            { type: 'line', label: '{{ __('admin.orders') }}', data: @js($m['chart']['orders']), yAxisID: 'y1', borderColor: '#14161c', backgroundColor: '#14161c', tension: .35, pointRadius: 2 },
                            { label: '{{ __('admin.sales_qar') }}', data: @js($m['chart']['totals']), backgroundColor: 'rgba(176,141,87,.75)', borderRadius: 6, maxBarThickness: 28 } ] },
                        options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true } } },
                            scales: { x: { grid: { display: false }, reverse: document.dir === 'rtl' }, y: { grid: { color: 'rgba(0,0,0,.05)' }, position: document.dir === 'rtl' ? 'right' : 'left' }, y1: { display: false } } }
                    });">
                <canvas x-ref="c" aria-label="{{ __('admin.sales_overview') }}" role="img"></canvas>
            </div>
        </div>
        <div class="card card-pad">
            <p class="eyebrow">{{ __('admin.operations') }}</p>
            <h2 class="mt-1 font-display text-2xl font-semibold">{{ __('admin.needs_attention') }}</h2>
            <ul class="mt-6 divide-y divide-sand-100 dark:divide-ink-800">
                @foreach ([
                    ['truck', __('admin.out_for_delivery'), $m['out_for_delivery'], 'admin.deliveries.index'],
                    ['qr', __('admin.awaiting_pickup'), $m['ready_for_pickup'], 'admin.pickup.index'],
                    ['refund', __('admin.pending_refunds'), $m['pending_refunds'], 'admin.refunds.index'],
                    ['gavel', __('admin.live_auctions'), $m['live_auctions'], 'admin.auctions.index'],
                    ['life-buoy', __('admin.open_tickets'), $m['open_tickets'], 'admin.support.index'],
                ] as [$icon, $label, $value, $route])
                    @continue($value === null)
                    <li>
                        <a @if(Route::has($route)) href="{{ route($route) }}" wire:navigate @endif class="group flex items-center gap-3 py-3.5">
                            <span class="grid size-9 place-items-center rounded-lg bg-sand-100 text-ink-600 dark:bg-ink-800 dark:text-sand-200"><x-icon :name="$icon" class="size-4" /></span>
                            <span class="flex-1 text-sm">{{ $label }}</span>
                            <span class="font-display text-xl font-semibold tabular-nums">{{ $value }}</span>
                            <x-icon name="chevron-right" class="size-4 text-ink-300 transition group-hover:text-brass-500 rtl-flip" />
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>

    <div class="card mt-6 overflow-hidden">
        <div class="flex items-center justify-between p-5 sm:p-6">
            <h2 class="font-display text-2xl font-semibold">{{ __('admin.recent_orders') }}</h2>
            @if (Route::has('admin.orders.index'))<a href="{{ route('admin.orders.index') }}" wire:navigate class="btn-ghost btn-sm">{{ __('admin.view_all') }}<x-icon name="arrow-right" class="size-4 rtl-flip" /></a>@endif
        </div>
        @if ($m['recent_orders']->isEmpty())
            <x-admin.empty :title="__('admin.no_orders_yet')" :description="__('admin.no_orders_hint')" icon="bag" />
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('admin.order') }}</th><th>{{ __('admin.customer') }}</th><th>{{ __('admin.status') }}</th><th>{{ __('admin.payment') }}</th><th class="!text-end">{{ __('admin.total') }}</th><th>{{ __('admin.placed') }}</th></tr></thead>
                <tbody>
                @foreach ($m['recent_orders'] as $o)
                    <tr>
                        <td class="font-medium text-ink-900 dark:text-ivory">@if(Route::has('admin.orders.show'))<a href="{{ route('admin.orders.show', $o->id) }}" wire:navigate class="hover:text-brass-600">{{ $o->number }}</a>@else{{ $o->number }}@endif</td>
                        <td>{{ $o->customer_name }}</td>
                        <td><x-admin.status :value="$o->status" /></td>
                        <td><x-admin.status :value="$o->payment_status" /></td>
                        <td class="text-end tabular-nums">{{ \App\Support\Money::of($o->grand_total)->format() }}</td>
                        <td class="text-ink-500">{{ \App\Support\BusinessTime::display($o->placed_at) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </div>
</div>
