@php
    $idleKeys = array_keys(\App\Domain\Checkout\Services\AbandonedCartService::IDLE);
    $kpiMoney = fn ($v) => preg_replace('/\.00(?=\s|$)/', '', \App\Support\Money::of($v ?? '0')->format()); // whole riyals are enough for a headline number
    $chip = 'inline-flex min-h-11 items-center gap-2 rounded-full border px-4 text-sm font-medium transition';
    $chipOn = 'border-ink-900 bg-ink-900 text-ivory dark:border-brass-500 dark:bg-brass-500 dark:text-ink-950';
    $chipOff = 'border-sand-300 bg-white hover:border-brass-400 dark:border-ink-600 dark:bg-ink-800';
@endphp
<div>
    <x-admin.page-header :title="__('abandoned_admin.title')" :eyebrow="__('nav.sales')" :description="__('abandoned_admin.hint')" />

    {{-- The automatic reminders: one switch, one sentence, the results and the settings window --}}
    @livewire(\App\Livewire\Admin\Sales\AbandonedAutoReminders::class, [], key('auto-reminders'))

    {{-- Numbers right now: a 2 x 2 grid on phones, one row on wide screens --}}
    <section aria-label="{{ __('abandoned_admin.kpi_label') }}" class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        @foreach ([
            ['kpi_carts', number_format($stats['carts']), 'cart', true, __('abandoned_admin.kpi_carts_hint')],
            ['kpi_value', $kpiMoney($stats['value']), 'cash', false, __('abandoned_admin.kpi_value_hint')],
            ['kpi_reminded', number_format($stats['reminded']), 'send', false, __('abandoned_admin.kpi_reminded_hint', ['count' => number_format(max(0, $stats['carts'] - $stats['reminded']))])],
            ['kpi_coupon', number_format($stats['with_coupon']), 'percent', false, __('abandoned_admin.kpi_coupon_hint')],
        ] as [$label, $value, $icon, $brass, $hint])
            <div class="card relative overflow-hidden p-4 sm:p-6">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs text-ink-500 dark:text-ink-400 sm:text-sm">{{ __('abandoned_admin.'.$label) }}</p>
                        <p class="mt-1 font-display text-2xl font-semibold tracking-tight text-ink-900 tabular-nums dark:text-ivory sm:mt-2 sm:text-3xl" dir="auto">{{ $value }}</p>
                    </div>
                    <span class="hidden size-11 shrink-0 place-items-center rounded-xl sm:grid {{ $brass ? 'bg-brass-50 text-brass-600 dark:bg-brass-500/15 dark:text-brass-300' : 'bg-sand-100 text-ink-600 dark:bg-ink-800 dark:text-sand-200' }}"><x-icon :name="$icon" /></span>
                </div>
                <p class="mt-2 text-[0.7rem] leading-snug text-ink-500 dark:text-ink-400 sm:mt-4 sm:text-xs">{{ $hint }}</p>
            </div>
        @endforeach
    </section>

    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('abandoned_admin.search')" />
            <div role="radiogroup" aria-label="{{ __('abandoned_admin.idle_label') }}" class="inline-flex rounded-xl border border-sand-300 bg-sand-50 p-0.5 dark:border-ink-600 dark:bg-ink-800">
                @foreach ($idleKeys as $k)
                    <button type="button" role="radio" aria-checked="{{ $idle === $k ? 'true' : 'false' }}" wire:click="setIdle('{{ $k }}')"
                            class="min-h-11 rounded-[0.65rem] px-3 text-sm font-medium tabular-nums transition {{ $idle === $k ? 'bg-white text-ink-900 shadow-sm dark:bg-ink-700 dark:text-ivory' : 'text-ink-500 hover:text-ink-900 dark:hover:text-ivory' }}">{{ __('abandoned_admin.idle_'.$k) }}</button>
                @endforeach
            </div>
            <div class="flex w-full items-center gap-2 sm:contents">
                <button type="button" wire:click="toggleCoupon" aria-pressed="{{ $withCoupon ? 'true' : 'false' }}" class="{{ $chip }} shrink-0 {{ $withCoupon ? $chipOn : $chipOff }}"><x-icon name="percent" class="size-4" />{{ __('abandoned_admin.has_coupon') }}</button>
                @if ($branches->count() > 1)
                    <select wire:model.live="branch" class="input min-w-0 flex-1 sm:w-44 sm:flex-none" aria-label="{{ __('admin.branch') }}">
                        <option value="">{{ __('admin.all_branches') }}</option>
                        @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->code }} · {{ $b->localized('name') }}</option>@endforeach
                    </select>
                @endif
            </div>
            <x-slot:meta><span aria-live="polite">{{ trans_choice('abandoned_admin.count', $total, ['count' => number_format($total)]) }}</span></x-slot:meta>
        </x-admin.toolbar>

        <div class="relative" wire:loading.class="opacity-60" wire:target="search,setIdle,toggleCoupon,branch,nextPage,previousPage,clearFilters">
            @if ($rows->isEmpty())
                <x-admin.empty :title="$filtered ? __('abandoned_admin.empty_filtered') : __('abandoned_admin.empty')" :description="$filtered ? __('abandoned_admin.empty_filtered_hint') : __('abandoned_admin.empty_hint')" icon="cart">
                    @if ($filtered)<button type="button" wire:click="clearFilters" class="btn-secondary btn-sm min-h-11">{{ __('abandoned_admin.clear_filters') }}</button>@endif
                </x-admin.empty>
            @else
                {{-- Desktop and tablet: table --}}
                <div class="table-wrap hidden md:block">
                    <table class="table">
                        <thead><tr>
                            <th scope="col">{{ __('abandoned_admin.col_customer') }}</th>
                            <th scope="col">{{ __('abandoned_admin.col_items') }}</th>
                            <th scope="col" class="!text-end">{{ __('abandoned_admin.col_value') }}</th>
                            <th scope="col">{{ __('abandoned_admin.col_idle') }}</th>
                            <th scope="col">{{ __('abandoned_admin.col_reminders') }}</th>
                            <th scope="col">{{ __('abandoned_admin.col_coupon') }}</th>
                            <th scope="col"><span class="sr-only">{{ __('abandoned_admin.col_actions') }}</span></th>
                        </tr></thead>
                        <tbody>
                        @foreach ($rows as $row)
                            <tr wire:key="ab-{{ $row->id }}" wire:click="openCart('{{ $row->id }}')" class="cursor-pointer">
                                <td class="max-w-[16rem]">
                                    <a href="{{ route('admin.sales.abandoned', ['cart' => $row->id]) }}" wire:click.prevent.stop="openCart('{{ $row->id }}')" class="block truncate font-medium text-ink-900 hover:text-brass-600 dark:text-ivory">{{ $row->user?->name ?? __('abandoned_admin.unknown_customer') }}</a>
                                    <span class="block text-xs text-ink-500" dir="ltr">{{ $row->user?->phone }}</span>
                                    @if ($row->branch)<span class="mt-0.5 inline-block font-mono text-[0.7rem] text-ink-500" dir="ltr" title="{{ $row->branch->localized('name') }}">{{ $row->branch->code }}</span>@endif
                                </td>
                                <td class="whitespace-nowrap">{{ trans_choice('abandoned_admin.items', (int) $row->units, ['count' => (int) $row->units]) }}</td>
                                <td class="whitespace-nowrap text-end font-medium"><x-admin.money :value="$row->value" /></td>
                                <td class="whitespace-nowrap" title="{{ \App\Support\BusinessTime::display($row->last_activity_at) }}">{{ $row->last_activity_at?->diffForHumans(null, \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</td>
                                <td class="whitespace-nowrap">
                                    @php $rem = \App\Domain\Checkout\Services\AbandonedCartService::reminderSummary($row); @endphp
                                    @if ($rem['sent'] > 0)
                                        <span class="badge-info">{{ trans_choice('abandoned_admin.reminders_sent', $rem['sent'], ['count' => $rem['sent']]) }}</span>
                                        <span class="block text-xs text-ink-500">{{ $rem['last']?->diffForHumans() }}</span>
                                    @else
                                        <span class="text-xs text-ink-400">{{ __('abandoned_admin.not_reminded') }}</span>
                                    @endif
                                </td>
                                <td>@if ($row->coupon_code)<span class="badge-brass font-mono" dir="ltr">{{ $row->coupon_code }}</span>@else<span class="text-ink-300">—</span>@endif</td>
                                <td class="whitespace-nowrap text-end">
                                    <span class="inline-flex items-center gap-1">
                                        <button type="button" wire:click.stop="openCart('{{ $row->id }}')" class="btn-secondary btn-sm min-h-11">{{ __('abandoned_admin.open') }}</button>
                                        @include('livewire.admin.sales.abandoned-row-menu')
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Phones: cards --}}
                <ul class="divide-y divide-sand-100 dark:divide-ink-800 md:hidden" aria-label="{{ __('abandoned_admin.title') }}">
                    @foreach ($rows as $row)
                        <li wire:key="abm-{{ $row->id }}" class="space-y-3 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate font-medium text-ink-900 dark:text-ivory">{{ $row->user?->name ?? __('abandoned_admin.unknown_customer') }}</p>
                                    <p class="text-xs text-ink-500" dir="ltr">{{ $row->user?->phone }}</p>
                                </div>
                                <x-admin.money :value="$row->value" class="text-base font-semibold" />
                            </div>
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-500">
                                <span>{{ trans_choice('abandoned_admin.items', (int) $row->units, ['count' => (int) $row->units]) }}</span>
                                <span class="inline-flex items-center gap-1"><x-icon name="clock" class="size-3.5" />{{ $row->last_activity_at?->diffForHumans(null, \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</span>
                                @php $remM = \App\Domain\Checkout\Services\AbandonedCartService::reminderSummary($row); @endphp
                                @if ($remM['sent'] > 0)<span class="badge-info">{{ trans_choice('abandoned_admin.reminders_sent', $remM['sent'], ['count' => $remM['sent']]) }}</span>@endif
                                @if ($row->coupon_code)<span class="badge-brass font-mono" dir="ltr">{{ $row->coupon_code }}</span>@endif
                            </div>
                            <div class="flex items-center gap-2">
                                <button type="button" wire:click="openCart('{{ $row->id }}')" class="btn-primary min-h-11 flex-1">{{ __('abandoned_admin.open') }}</button>
                                @include('livewire.admin.sales.abandoned-row-menu')
                            </div>
                        </li>
                    @endforeach
                </ul>

                <nav class="flex items-center justify-between gap-3 border-t border-sand-100 p-4 dark:border-ink-800" aria-label="{{ __('org_admin.page_nav') }}">
                    <p class="text-xs text-ink-500 tabular-nums">{{ trans_choice('abandoned_admin.showing', $rows->count(), ['count' => $rows->count()]) }}</p>
                    <div class="flex items-center gap-1">
                        <button type="button" wire:click="previousPage" @disabled($after === '' && $trail === []) class="btn-ghost btn-sm min-h-11 px-3" aria-label="{{ __('org_admin.prev_page') }}"><x-icon name="chevron-left" class="size-4 rtl-flip" /></button>
                        <button type="button" wire:click="nextPage('{{ $next }}')" @disabled($next === null) class="btn-ghost btn-sm min-h-11 px-3" aria-label="{{ __('org_admin.next_page') }}"><x-icon name="chevron-right" class="size-4 rtl-flip" /></button>
                    </div>
                </nav>
            @endif
        </div>
    </div>

    {{-- The cart of the customer, beside the list: a side window, a bottom sheet on phones. --}}
    <x-admin.dialog wire:model.live="showCart" max-width="max-w-xl" :title="isset($window['customer']) ? __('abandoned_admin.window_title', ['name' => $window['customer']->name]) : __('abandoned_admin.window_fallback')">
        @include('livewire.admin.sales.abandoned-window')
        @if ($window && ! isset($window['gone']) && $canChange)
            <x-slot:footer>
                <div class="grid gap-2">
                    <button type="button" wire:click="placeOrder" wire:loading.attr="disabled" wire:target="placeOrder" class="btn-brass min-h-12 w-full">
                        <x-icon name="arrow-right" class="size-4 rtl-flip" /><span wire:loading.remove wire:target="placeOrder">{{ __('abandoned_admin.place_order') }}</span><span wire:loading wire:target="placeOrder">{{ __('abandoned_admin.opening') }}</span>
                    </button>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="remind('{{ $cartId }}')" wire:loading.attr="disabled" wire:target="remind" @disabled($window['reminder']['status'] !== 'ok') class="btn-secondary min-h-11 flex-1">
                            <x-icon name="send" class="size-4" />{{ __('abandoned_admin.send_reminder') }}
                        </button>
                        <button type="button" wire:click="discard('{{ $cartId }}')" wire:loading.attr="disabled" wire:target="discard" class="btn-secondary min-h-11 flex-1 text-ruby-soft"><x-icon name="trash" class="size-4" />{{ __('abandoned_admin.discard') }}</button>
                        <button type="button" @click="requestClose()" class="btn-ghost hidden min-h-11 sm:inline-flex">{{ __('admin.close') }}</button>
                    </div>
                </div>
            </x-slot:footer>
        @endif
    </x-admin.dialog>
</div>
