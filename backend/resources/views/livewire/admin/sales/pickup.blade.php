@php
    $tabs = [
        'ready' => ['qr', __('sales_admin.pickup_tab_ready'), $counts['ready']],
        'preparing' => ['package', __('sales_admin.pickup_tab_preparing'), $counts['preparing']],
        'collected' => ['check', __('sales_admin.pickup_tab_collected'), $counts['collected']],
    ];
@endphp
<div>
    <x-admin.page-header :title="__('nav.pickup')" :eyebrow="__('nav.sales')" :description="__('sales_admin.pickup_hint')">
        <x-slot:actions>
            @if ($canScan)<a href="{{ route('admin.pickup.scan', array_filter(['branch' => $branch ?: null])) }}" wire:navigate class="btn-primary"><x-icon name="camera" class="size-4" />{{ __('sales_admin.open_scanner') }}</a>@endif
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.tabs :tabs="$tabs" :current="$tab" />

    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('sales_admin.pickup_search')" />
            @if ($branches->count() > 1)
                <select wire:model.live="branch" class="input sm:w-52" aria-label="{{ __('admin.branch') }}">
                    <option value="">{{ __('admin.all_my_branches') }}</option>
                    @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->code }} · {{ $b->localized('name') }}</option>@endforeach
                </select>
            @endif
            <x-slot:meta>{{ trans_choice('sales_admin.orders_count', $rows->total(), ['count' => $rows->total()]) }}</x-slot:meta>
        </x-admin.toolbar>

        @if ($rows->isEmpty())
            <x-admin.empty :title="__('sales_admin.pickup_empty_'.$tab)" :description="__('sales_admin.pickup_empty_hint')" icon="store" />
        @elseif ($tab === 'preparing')
            <ul class="divide-y divide-sand-100 dark:divide-ink-800">
                @foreach ($rows as $f)
                    <li class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:px-6" wire:key="pf-{{ $f->id }}">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-admin.order-peek-link :id="$f->order_id" :number="$f->order->number" :can="$this->can('orders.view', $f->branch_id)" class="font-medium" />
                                <x-admin.status :value="$f->status->value" />
                                <span class="badge-neutral font-mono" dir="ltr">{{ $f->branch->code }}</span>
                            </div>
                            <p class="mt-1 truncate text-sm text-ink-500">{{ $f->order->customer_name }} · {{ trans_choice('sales_admin.units', (int) $f->units, ['count' => (int) $f->units]) }}@if ($f->slot_date) · {{ \App\Support\BusinessTime::display($f->slot_date, 'D d M') }}@endif</p>
                        </div>
                        @if ($this->can('pickup.edit', $f->branch_id))
                            <button type="button" wire:click="markReady('{{ $f->id }}')" wire:loading.attr="disabled" wire:target="markReady('{{ $f->id }}')" class="btn-secondary btn-sm max-md:min-h-11 self-start sm:self-auto"><x-icon name="qr" class="size-4" />{{ __('sales_admin.act_pickup_ready') }}</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @else
            <ul class="grid gap-4 p-4 sm:grid-cols-2 sm:p-6 xl:grid-cols-3">
                @foreach ($rows as $r)
                    @php
                        $o = $r->fulfillment->order;
                        $due = \App\Support\Money::of($o->grand_total)->minus(\App\Support\Money::of($o->paid_total));
                        $left = $r->fulfillment->items->sum(fn ($i) => $i->quantity - $i->quantity_cancelled - $i->quantity_handed_over);
                        $expiring = $r->token_expires_at && $r->token_expires_at->lt(now()->addHours(12));
                    @endphp
                    <li class="min-w-0 rounded-2xl border border-sand-200/70 bg-white p-5 transition hover:border-brass-300 dark:border-ink-700 dark:bg-ink-900" wire:key="pr-{{ $r->id }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <x-admin.order-peek-link :id="$o->id" :number="$o->number" :can="$this->can('orders.view', $r->branch_id)" class="font-medium" />
                                <p class="truncate text-sm text-ink-600 dark:text-sand-200">{{ $o->customer_name }}</p>
                            </div>
                            <x-admin.status :value="$r->status" />
                        </div>
                        <dl class="mt-4 grid grid-cols-2 gap-3 text-xs">
                            <div><dt class="text-ink-500">{{ __('admin.branch') }}</dt><dd class="font-mono font-medium" dir="ltr">{{ $r->branch->code }}</dd></div>
                            <div><dt class="text-ink-500">{{ $tab === 'collected' ? __('sales_admin.collected_at') : __('sales_admin.waiting_since') }}</dt><dd class="tabular-nums">{{ $tab === 'collected' ? \App\Support\BusinessTime::display($r->collected_at, 'H:i') : $r->ready_at?->locale(app()->getLocale())->diffForHumans() }}</dd></div>
                            @if ($tab !== 'collected')
                                <div><dt class="text-ink-500">{{ __('sales_admin.items_left') }}</dt><dd class="tabular-nums font-medium">{{ $left }}</dd></div>
                                <div><dt class="text-ink-500">{{ __('sales_admin.to_collect') }}</dt><dd>@if ($due->isPositive() && ! $o->payment_status->isSettled())<x-admin.money :value="$due->toDecimal()" class="font-medium text-amber-soft" />@else<span class="text-emerald-soft">{{ __('status.paid') }}</span>@endif</dd></div>
                            @endif
                        </dl>
                        @if ($tab !== 'collected' && $expiring)<p class="mt-3 text-xs text-ruby-soft"><x-icon name="clock" class="me-1 inline size-3.5" />{{ __('sales_admin.pass_expires', ['at' => \App\Support\BusinessTime::display($r->token_expires_at)]) }}</p>@endif
                    </li>
                @endforeach
            </ul>
        @endif
        @if ($rows->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $rows->links('components.admin.pagination') }}</div>@endif
    </div>

    @include('livewire.admin.sales.partials.order-peek')
</div>
