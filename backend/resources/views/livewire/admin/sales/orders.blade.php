@php
    $tabs = [
        'all' => ['bag', __('sales_admin.tab_all')],
        'needs_action' => ['alert', __('sales_admin.tab_needs_action'), $counts['needs_action']],
        'pending_payment' => ['clock', __('sales_admin.tab_pending_payment'), $counts['pending_payment']],
        'ready' => ['check', __('sales_admin.tab_ready'), $counts['ready']],
        'out_for_delivery' => ['truck', __('sales_admin.tab_out_for_delivery'), $counts['out_for_delivery']],
    ];
@endphp
<div>
    <x-admin.page-header :title="__('nav.orders', [], 'en').' · '.__('nav.orders', [], 'ar')" :eyebrow="__('nav.sales', [], 'en').' · '.__('nav.sales', [], 'ar')" :description="__('sales_admin.orders_hint')">
        @if ($this->can('orders.create'))
            <x-slot:actions><a href="{{ route('admin.orders.create') }}" wire:navigate class="btn-primary min-h-11"><x-icon name="plus" class="size-4" />{{ __('neworder_admin.new_order') }}</a></x-slot:actions>
        @endif
    </x-admin.page-header>

    <x-admin.tabs :tabs="$tabs" :current="$tab" />

    {{-- One-tap kinds: cash on delivery, SADAD payment, pickup — with live counts. --}}
    <div class="mb-4 flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('sales_admin.quick_kind') }}">
        <button type="button" wire:click="setQuick('')" aria-pressed="{{ $quick === '' ? 'true' : 'false' }}" class="inline-flex min-h-11 items-center gap-2 rounded-full border px-4 text-sm font-medium transition {{ $quick === '' ? 'border-ink-900 bg-ink-900 text-ivory dark:border-brass-500 dark:bg-brass-500 dark:text-ink-950' : 'border-sand-300 bg-white hover:border-brass-400 dark:border-ink-600 dark:bg-ink-800' }}">
            <x-admin.bi :en="__('sales_admin.tab_all', [], 'en')" :ar="__('sales_admin.tab_all', [], 'ar')" />
        </button>
        @foreach ([['cod', 'cash', 'status.cod'], ['sadad', 'credit-card', 'status.sadad'], ['pickup', 'store', 'sales_admin.type_pickup']] as [$k, $icon, $label])
            <button type="button" wire:click="setQuick('{{ $k }}')" aria-pressed="{{ $quick === $k ? 'true' : 'false' }}" class="inline-flex min-h-11 items-center gap-2 rounded-full border px-4 text-sm font-medium transition {{ $quick === $k ? 'border-ink-900 bg-ink-900 text-ivory dark:border-brass-500 dark:bg-brass-500 dark:text-ink-950' : 'border-sand-300 bg-white hover:border-brass-400 dark:border-ink-600 dark:bg-ink-800' }}">
                <x-icon :name="$icon" class="size-4" />
                <x-admin.bi :en="__($label, [], 'en')" :ar="__($label, [], 'ar')" />
                <span class="rounded-full px-1.5 text-[0.7rem] tabular-nums {{ $quick === $k ? 'bg-white/15' : 'bg-sand-100 dark:bg-ink-700' }}">{{ $quickCounts[$k] }}</span>
            </button>
        @endforeach
        <span class="ms-auto text-xs text-ink-500" aria-live="polite">{{ trans_choice('sales_admin.orders_count', $orders->total(), ['count' => $orders->total()]) }}</span>
    </div>

    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('sales_admin.orders_search')" />
            <select wire:model.live="filterStatus" class="input sm:w-48" aria-label="{{ __('admin.status') }}">
                <option value="">{{ __('admin.all_statuses') }}</option>
                @foreach (\App\Domain\Orders\Enums\OrderStatus::cases() as $st)<option value="{{ $st->value }}">{{ __('status.'.$st->value) }}</option>@endforeach
            </select>
            @if ($branches->count() > 1)
                <select wire:model.live="branch" class="input sm:w-44" aria-label="{{ __('admin.branch') }}">
                    <option value="">{{ __('admin.all_branches') }}</option>
                    @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->code }} · {{ $b->localized('name') }}</option>@endforeach
                </select>
            @endif
            <div x-data="{ more: @js((bool) $filtered) }" class="contents">
                <button type="button" class="btn-ghost btn-sm" @click="more = !more" :aria-expanded="more"><x-icon name="filter" class="size-4" />{{ __('sales_admin.more_filters') }}</button>
                <div x-show="more" x-cloak class="grid w-full gap-3 sm:grid-cols-2 lg:grid-cols-5 [&>*]:min-w-0">
                    <select wire:model.live="filterPayment" class="input" aria-label="{{ __('sales_admin.payment_status') }}">
                        <option value="">{{ __('sales_admin.any_payment_status') }}</option>
                        @foreach (\App\Domain\Payments\Enums\PaymentStatus::cases() as $st)<option value="{{ $st->value }}">{{ __('status.'.$st->value) }}</option>@endforeach
                    </select>
                    <select wire:model.live="filterMethod" class="input" aria-label="{{ __('sales_admin.payment_method') }}">
                        <option value="">{{ __('sales_admin.any_payment_method') }}</option>
                        @foreach (['sadad', 'cod', 'pay_at_pickup'] as $m)<option value="{{ $m }}">{{ __('status.'.$m) }}</option>@endforeach
                    </select>
                    <select wire:model.live="filterType" class="input" aria-label="{{ __('sales_admin.fulfillment_type') }}">
                        <option value="">{{ __('sales_admin.any_fulfillment') }}</option>
                        @foreach (['delivery', 'pickup'] as $m)<option value="{{ $m }}">{{ __('sales_admin.type_'.$m) }}</option>@endforeach
                    </select>
                    <label class="relative block"><span class="sr-only">{{ __('sales_admin.from_date') }}</span><input type="date" wire:model.live="filterFrom" class="input" title="{{ __('sales_admin.from_date') }}"></label>
                    <label class="relative block"><span class="sr-only">{{ __('sales_admin.to_date') }}</span><input type="date" wire:model.live="filterTo" class="input" title="{{ __('sales_admin.to_date') }}"></label>
                </div>
            </div>
            <x-slot:meta>{{ trans_choice('sales_admin.orders_count', $orders->total(), ['count' => $orders->total()]) }}</x-slot:meta>
        </x-admin.toolbar>

        @if ($orders->isEmpty())
            <x-admin.empty :title="$filtered || $search || $tab !== 'all' ? __('sales_admin.orders_empty_filtered') : __('sales_admin.orders_empty')" :description="__('sales_admin.orders_empty_hint')" icon="bag">
                @if ($filtered || $search)<button type="button" wire:click="clearFilters" class="btn-secondary btn-sm">{{ __('sales_admin.clear_filters') }}</button>@endif
            </x-admin.empty>
        @else
            <div class="table-wrap" wire:loading.class="opacity-60">
                <table class="table">
                    <thead><tr>
                        @foreach ([['admin.order', 'order'], ['admin.customer', 'cust'], ['sales_admin.branches', 'br'], ['sales_admin.kind', 'kind'], ['admin.payment', 'pay'], ['admin.total', 'tot'], ['admin.status', 'st']] as [$k, $id])
                            <th class="{{ $id === 'tot' ? '!text-end' : '' }}"><x-admin.bi :en="__($k, [], 'en')" :ar="__($k, [], 'ar')" /></th>
                        @endforeach
                        <th class="w-12"></th>
                    </tr></thead>
                    <tbody>
                    @foreach ($orders as $o)
                        <tr wire:key="o-{{ $o->id }}">
                            <td>
                                <a href="{{ route('admin.orders.show', $o) }}" wire:navigate class="font-mono font-medium text-ink-900 hover:text-brass-600 dark:text-ivory" dir="ltr">{{ $o->number }}</a>
                                <span class="block text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($o->placed_at) }}</span>
                            </td>
                            <td class="max-w-[14rem]">
                                <span class="block truncate font-medium">{{ $o->customer_name }}</span>
                                <span class="block text-xs text-ink-500" dir="ltr">{{ $o->customer_phone }}</span>
                            </td>
                            <td>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($o->fulfillments as $f)
                                        <span class="inline-flex items-center gap-1.5 rounded-lg border border-sand-200 px-2 py-0.5 text-xs dark:border-ink-700" title="{{ $f->branch->localized('name') }} · {{ __('status.'.$f->status->value) }}">
                                            <x-icon :name="$f->type === 'pickup' ? 'store' : 'truck'" class="size-3 text-ink-400" />
                                            <span class="font-mono" dir="ltr">{{ $f->branch->code }}</span>
                                            <x-admin.status :value="$f->status->value" class="!px-1.5 !py-0 text-[0.65rem]" />
                                        </span>
                                    @endforeach
                                </div>
                            </td>
                            <td><x-admin.order-kind :method="$o->payment_method" :type="$o->fulfillment_type" /></td>
                            <td><x-admin.status :value="$o->payment_status->value" bi /></td>
                            <td class="text-end font-medium"><x-admin.money :value="$o->grand_total" /></td>
                            <td><x-admin.status :value="$o->status->value" bi /></td>
                            <td><a href="{{ route('admin.orders.show', $o) }}" wire:navigate class="btn-ghost btn-sm" aria-label="{{ __('admin.details') }}"><x-icon name="chevron-right" class="size-4 rtl-flip" /></a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($orders->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $orders->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>
</div>
