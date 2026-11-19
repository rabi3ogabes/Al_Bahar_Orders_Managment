<div>
    <x-admin.page-header :title="$greeting.(app()->getLocale() === 'ar' ? '، ' : ', ').\Illuminate\Support\Str::before(auth()->user()->name, ' ')" :eyebrow="\App\Support\BusinessTime::now()->locale(app()->getLocale())->translatedFormat('l, d F Y')" :description="__('admin.dashboard_hint')">
        <x-slot:actions>
            @if ($branches->count() > 1)
                <select wire:model.live="branchId" class="input w-auto min-w-52" aria-label="{{ __('admin.branch') }}">
                    <option value="">{{ __('admin.all_my_branches') }}</option>
                    @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->localized('name') }}</option>@endforeach
                </select>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    @php
        $can = $m['can'];
        $money = array_values(array_filter([
            $can['sales'] ? 'sales' : null, $can['collected'] ? 'collected' : null, $can['sales'] ? 'orders' : null,
        ]));
        $work = array_values(array_filter([$can['orders'] ? 'prepare' : null, $can['stock'] ? 'stock' : null]));
        $attention = [
            ['truck', __('admin.out_for_delivery'), $m['out_for_delivery'], 'admin.deliveries.index'],
            ['qr', __('admin.awaiting_pickup'), $m['ready_for_pickup'], 'admin.pickup.index'],
            ['refund', __('admin.pending_refunds'), $m['pending_refunds'], 'admin.refunds.index'],
            ['gavel', __('admin.live_auctions'), $m['live_auctions'], 'admin.auctions.index'],
            ['life-buoy', __('admin.open_tickets'), $m['open_tickets'], 'admin.support.index'],
        ];
        $hasAttention = collect($attention)->contains(fn ($row) => $row[2] !== null);
        $cols = fn (int $n) => [1 => 'xl:grid-cols-1', 2 => 'xl:grid-cols-2'][$n] ?? 'xl:grid-cols-3';
    @endphp

    @if ($money !== [])
        <div class="grid gap-5 sm:grid-cols-2 {{ $cols(count($money)) }}" data-tiles="money">
            @if ($can['sales'])
                <x-admin.kpi :label="__('owner_numbers.sales_today')" :note="__('owner_numbers.sales_today_note')" :value="$m['sales_today']" icon="wallet" tone="brass" :hint="__('owner_numbers.sales_today_hint')" data-tile="sales-today" />
            @endif
            @if ($can['collected'])
                <x-admin.kpi :label="__('owner_numbers.collected_today')" :note="__('owner_numbers.collected_today_note')" :value="$m['collected_today']" icon="cash" :hint="__('owner_numbers.collected_split', ['online' => $m['collected_online'], 'cash' => $m['collected_cash']])" data-tile="collected-today" />
            @endif
            @if ($can['sales'])
                <x-admin.kpi :label="__('owner_numbers.orders_today')" :note="__('owner_numbers.orders_today_note')" :value="number_format($m['orders_today'])" icon="bag" data-tile="orders-today" />
            @endif
        </div>
    @endif

    @if ($work !== [])
        <div class="grid gap-5 sm:grid-cols-2 {{ $money !== [] ? 'mt-5' : '' }}" data-tiles="work">
            @if ($can['orders'])
                <x-admin.stat :label="__('admin.to_prepare')" :value="number_format($m['pending_fulfillments'])" icon="package" :hint="$can['pickup'] ? __('admin.ready_pickup_count', ['count' => $m['ready_for_pickup']]) : null" data-tile="to-prepare" />
            @endif
            @if ($can['stock'])
                <x-admin.kpi :label="__('admin.low_stock')" :note="__('owner_numbers.low_stock_note', ['n' => $m['low_stock_threshold']])" :value="number_format($m['low_stock'])" icon="alert" :hint="__('owner_numbers.out_of_stock_hint', ['count' => number_format($m['out_of_stock'])])" data-tile="low-stock" />
            @endif
        </div>
    @endif

    @if ($m['chart'] !== null || $hasAttention)
    <div class="mt-6 grid gap-6 xl:grid-cols-3">
        @if ($m['chart'] !== null)
        <div class="card card-pad {{ $hasAttention ? 'xl:col-span-2' : 'xl:col-span-3' }}">
            <div class="flex items-center justify-between">
                <div><p class="eyebrow">{{ __('admin.last_14_days') }}</p><h2 class="mt-1 font-display text-2xl font-semibold">{{ __('admin.sales_overview') }}</h2>
                    <p class="mt-1 max-w-xl text-xs text-ink-500">{{ __('owner_numbers.sales_chart_note') }}</p></div>
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
        @endif
        @if ($hasAttention)
        <div class="card card-pad {{ $m['chart'] === null ? 'xl:col-span-3' : '' }}">
            <p class="eyebrow">{{ __('admin.operations') }}</p>
            <h2 class="mt-1 font-display text-2xl font-semibold">{{ __('admin.needs_attention') }}</h2>
            <ul class="mt-6 divide-y divide-sand-100 dark:divide-ink-800">
                @foreach ($attention as [$icon, $label, $value, $route])
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
        @endif
    </div>
    @endif

    @if ($shortcuts !== [])
        <div class="card card-pad" data-welcome>
            <h2 class="font-display text-2xl font-semibold">{{ __('owner_numbers.welcome_title') }}</h2>
            <p class="mt-2 max-w-2xl text-sm text-ink-500">{{ __('owner_numbers.welcome_hint') }}</p>
            <p class="mt-6 text-xs font-semibold uppercase tracking-wider text-ink-500">{{ __('owner_numbers.shortcuts') }}</p>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($shortcuts as $item)
                    <a href="{{ route($item['route']) }}" wire:navigate class="group flex items-center gap-3 rounded-xl border border-sand-200 p-3 transition hover:border-brass-500 dark:border-ink-700">
                        <span class="grid size-9 place-items-center rounded-lg bg-sand-100 text-ink-600 dark:bg-ink-800 dark:text-sand-200"><x-icon :name="$item['icon']" class="size-4" /></span>
                        <span class="flex-1 text-sm font-medium">{{ __($item['label']) }}</span>
                        <x-icon name="chevron-right" class="size-4 text-ink-300 transition group-hover:text-brass-500 rtl-flip" />
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    @if ($m['recent_orders'] !== null)
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
    @endif

    @if ($showHealth)
        <livewire:admin.system-health-card lazy />
    @endif
</div>
