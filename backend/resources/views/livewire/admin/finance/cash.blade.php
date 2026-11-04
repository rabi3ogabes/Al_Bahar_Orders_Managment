<div>
    <x-admin.page-header :title="__('nav.cash')" :eyebrow="__('nav.finance')" :description="__('finance_admin.cash_hint')" />

    <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4 [&>*]:min-w-0">
        <x-admin.stat :label="__('finance_admin.collected')" :value="\App\Support\Money::of($totals->collected)->format()" icon="wallet" tone="brass" />
        <x-admin.stat :label="__('sales_admin.tender_cash')" :value="\App\Support\Money::of($totals->cash)->format()" icon="cash" />
        <x-admin.stat :label="__('sales_admin.tender_card')" :value="\App\Support\Money::of($totals->card)->format()" icon="credit-card" />
        <x-admin.stat :label="__('finance_admin.not_handed_in')" :value="\App\Support\Money::of($totals->not_handed_in)->format()" icon="alert" :hint="$totals->failed ? trans_choice('finance_admin.n_failed_collections', (int) $totals->failed, ['count' => (int) $totals->failed]) : null" />
    </div>

    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('finance_admin.cash_search')" />
            <select wire:model.live="filterContext" class="input sm:w-44" aria-label="{{ __('finance_admin.context') }}">
                <option value="">{{ __('finance_admin.all_contexts') }}</option>
                <option value="cod">{{ __('status.cod') }}</option><option value="pay_at_pickup">{{ __('status.pay_at_pickup') }}</option>
            </select>
            <select wire:model.live="filterTender" class="input sm:w-36" aria-label="{{ __('sales_admin.tender') }}">
                <option value="">{{ __('finance_admin.all_tenders') }}</option>
                <option value="cash">{{ __('sales_admin.tender_cash') }}</option><option value="card_terminal">{{ __('sales_admin.tender_card') }}</option>
            </select>
            <select wire:model.live="filterStatus" class="input sm:w-36" aria-label="{{ __('admin.status') }}">
                <option value="">{{ __('admin.all_statuses') }}</option>
                @foreach (['collected', 'partial', 'failed'] as $st)<option value="{{ $st }}">{{ __('status.'.$st) }}</option>@endforeach
            </select>
            @if ($branches->count() > 1)
                <select wire:model.live="branch" class="input sm:w-36" aria-label="{{ __('admin.branch') }}"><option value="">{{ __('admin.all_branches') }}</option>@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->code }}</option>@endforeach</select>
            @endif
            <div class="flex items-center gap-2">
                <input type="date" wire:model.live="filterFrom" class="input w-auto" aria-label="{{ __('sales_admin.from_date') }}">
                <span class="text-ink-400">–</span>
                <input type="date" wire:model.live="filterTo" class="input w-auto" aria-label="{{ __('sales_admin.to_date') }}">
            </div>
        </x-admin.toolbar>
        @if ($rows->isEmpty())
            <x-admin.empty :title="__('finance_admin.cash_empty')" :description="__('finance_admin.cash_empty_hint')" icon="cash" />
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('admin.date') }}</th><th>{{ __('admin.order') }}</th><th>{{ __('finance_admin.collector') }}</th><th>{{ __('finance_admin.context') }}</th><th class="!text-end">{{ __('finance_admin.due') }}</th><th class="!text-end">{{ __('finance_admin.collected') }}</th><th>{{ __('admin.status') }}</th></tr></thead>
                <tbody>@foreach ($rows as $c)
                    <tr wire:key="cc-{{ $c->id }}">
                        <td class="text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($c->collected_at) }}</td>
                        <td>@if ($c->payment?->order)<a href="{{ route('admin.orders.show', $c->payment->order_id) }}" wire:navigate class="font-mono text-sm hover:text-brass-600" dir="ltr">{{ $c->payment->order->number }}</a>@endif<span class="block font-mono text-xs text-ink-500" dir="ltr">{{ $c->branch?->code }}@if ($c->receipt_reference) · {{ $c->receipt_reference }}@endif</span></td>
                        <td class="text-sm">{{ $c->collector?->name }}</td>
                        <td><span class="text-sm">{{ __('status.'.$c->context) }}</span><span class="block text-xs text-ink-500">{{ __('sales_admin.tender_'.($c->tender === 'card_terminal' ? 'card' : 'cash')) }}</span></td>
                        <td class="text-end"><x-admin.money :value="$c->amount_due" /></td>
                        <td class="text-end font-medium"><x-admin.money :value="$c->amount_collected" /></td>
                        <td><x-admin.status :value="$c->status" />@if ($c->failure_reason)<span class="mt-1 block text-xs text-ruby-soft">{{ \Illuminate\Support\Str::headline($c->failure_reason) }}</span>@endif
                            @if ($c->tender === 'cash' && $c->status !== 'failed')<span class="mt-1 block text-[0.7rem] {{ $c->handed_in_at ? 'text-emerald-soft' : 'text-amber-soft' }}">{{ $c->handed_in_at ? __('finance_admin.handed_in') : __('finance_admin.not_handed_in') }}</span>@endif</td>
                    </tr>
                @endforeach</tbody>
            </table></div>
            @if ($rows->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $rows->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>
</div>
