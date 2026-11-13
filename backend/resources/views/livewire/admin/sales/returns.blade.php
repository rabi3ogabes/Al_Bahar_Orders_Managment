<div>
    <x-admin.page-header :title="__('nav.returns')" :eyebrow="__('nav.sales')" :description="__('sales_admin.returns_hint')" />

    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('sales_admin.returns_search')" />
            <select wire:model.live="filterStatus" class="input sm:w-48" aria-label="{{ __('admin.status') }}">
                <option value="open">{{ __('sales_admin.open_returns') }}</option>
                <option value="">{{ __('admin.all_statuses') }}</option>
                @foreach (['requested', 'approved', 'rejected', 'received', 'refunded', 'closed'] as $st)<option value="{{ $st }}">{{ __('status.'.$st) }}</option>@endforeach
            </select>
            @if ($branches->count() > 1)
                <select wire:model.live="branch" class="input sm:w-48" aria-label="{{ __('admin.branch') }}">
                    <option value="">{{ __('admin.all_my_branches') }}</option>
                    @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->code }} · {{ $b->localized('name') }}</option>@endforeach
                </select>
            @endif
            <x-slot:meta>@if ($pending)<span class="badge-warning">{{ trans_choice('sales_admin.n_awaiting_decision', $pending, ['count' => $pending]) }}</span>@endif</x-slot:meta>
        </x-admin.toolbar>

        @if ($returns->isEmpty())
            <x-admin.empty :title="__('sales_admin.returns_empty')" :description="__('sales_admin.returns_empty_hint')" icon="refund" />
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('sales_admin.return') }}</th><th>{{ __('admin.order') }}</th><th>{{ __('admin.customer') }}</th><th>{{ __('admin.reason') }}</th><th class="!text-end">{{ __('sales_admin.lines') }}</th><th>{{ __('admin.status') }}</th><th class="w-12"></th></tr></thead>
                <tbody>@foreach ($returns as $rr)
                    <tr wire:key="rr-{{ $rr->id }}" class="cursor-pointer focus:outline-none" tabindex="-1" wire:click="select('{{ $rr->id }}')">
                        <td>
                            <a href="{{ route('admin.returns.index', ['view' => $rr->id]) }}" wire:click.prevent.stop="select('{{ $rr->id }}')" class="font-mono font-medium text-ink-900 hover:text-brass-600 dark:text-ivory" dir="ltr">{{ $rr->number }}</a>
                            <span class="block text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($rr->created_at) }}</span>
                        </td>
                        <td><x-admin.order-peek-link :id="$rr->order_id" :number="$rr->order->number" :can="$this->can('orders.view', $rr->branch_id)" class="text-sm" /></td>
                        <td class="max-w-[12rem] truncate">{{ $rr->order->customer_name }}</td>
                        <td><span class="badge-neutral">{{ __('sales_admin.reason_'.$rr->reason_code) }}</span></td>
                        <td class="text-end tabular-nums">{{ collect($rr->items)->sum('quantity') }}</td>
                        <td><x-admin.status :value="$rr->status" /></td>
                        <td><span class="btn-ghost btn-sm pointer-events-none" aria-hidden="true"><x-icon name="chevron-right" class="size-4 rtl-flip" /></span></td>
                    </tr>
                @endforeach</tbody>
            </table></div>
            @if ($returns->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $returns->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>

    {{-- Return side window: facts and the decision's inputs scroll in the body, the step's buttons are pinned in the footer. --}}
    @php
        $canApprove = $sel && $this->can('orders.approve', $sel->branch_id);
        $canEditRet = $sel && $this->can('orders.edit', $sel->branch_id);
        $canRefund = $sel && $this->can('refunds.create', $sel->branch_id);
        $showFooter = $sel && match ($sel->status) {
            'requested' => $canApprove, 'approved' => $canEditRet, 'received' => $canApprove || $canRefund, 'refunded' => $canApprove, default => false,
        };
    @endphp
    <x-admin.dialog wire:model="showDetail" :title="$sel ? __('sales_admin.return').' '.$sel->number : ''" :description="$sel ? __('sales_admin.return_for', ['order' => $sel->order->number, 'name' => $sel->order->customer_name]) : null" max-width="max-w-2xl">
        @if ($sel)
            <x-admin.drawer-bar close="closeDetail">
                <x-admin.status :value="$sel->status" />
                <span class="badge-neutral">{{ __('sales_admin.reason_'.$sel->reason_code) }}</span>
                @if ($refund)<a href="{{ route('admin.refunds.index', ['search' => $refund->number]) }}" wire:navigate class="badge-info">{{ $refund->number }} · {{ __('status.'.$refund->status) }}</a>@endif
                <x-admin.order-peek-link :id="$sel->order_id" :number="$sel->order->number" :can="$this->can('orders.view', $sel->branch_id)" class="text-xs underline-offset-2 hover:underline" />
            </x-admin.drawer-bar>
            <div class="space-y-5">
                @if ($sel->reason)<blockquote class="rounded-xl bg-sand-50 p-3.5 text-sm text-ink-700 dark:bg-ink-800/60 dark:text-sand-100">“{{ $sel->reason }}”</blockquote>@endif
                <div class="overflow-hidden rounded-xl border border-sand-200/70 dark:border-ink-700"><div class="table-wrap"><table class="table">
                    <thead><tr><th>{{ __('sales_admin.item') }}</th><th class="!text-end">{{ __('admin.quantity') }}</th><th class="!text-end">{{ __('sales_admin.unit_price') }}</th></tr></thead>
                    <tbody>@foreach ($sel->items as $line)
                        @php $it = $items[$line['order_item_id']] ?? null; @endphp
                        <tr><td>@if ($it)<span class="block font-medium">{{ $it->name() }}</span><span class="font-mono text-xs text-ink-500" dir="ltr">{{ $it->sku }}</span>@else — @endif</td>
                            <td class="text-end tabular-nums">{{ $line['quantity'] }}</td><td class="text-end">@if ($it)<x-admin.money :value="$it->unit_final_price" />@endif</td></tr>
                    @endforeach</tbody>
                </table></div></div>

                @if ($sel->status === 'requested' && $canApprove)
                    <x-admin.field :label="__('sales_admin.decision_note')" for="note" :hint="__('sales_admin.reject_needs_reason')"><textarea id="note" wire:model="note" rows="3" class="input"></textarea></x-admin.field>
                @elseif ($sel->status === 'approved' && $canEditRet)
                    <x-admin.toggle :label="__('sales_admin.restock_items')" :hint="__('sales_admin.restock_hint')" wire:model="restock" />
                @elseif ($sel->status === 'received' && $canRefund)
                    <div class="space-y-3 border-t border-sand-100 pt-5 dark:border-ink-800">
                        <h3 class="font-semibold">{{ __('sales_admin.raise_refund') }}</h3>
                        <div class="grid gap-3 sm:grid-cols-2 [&>*]:min-w-0">
                            <x-admin.field :label="__('finance_admin.amount')" for="refundAmount" required><input id="refundAmount" type="number" step="0.01" min="0.01" wire:model="refundAmount" class="input tabular-nums" dir="ltr"></x-admin.field>
                            <x-admin.field :label="__('finance_admin.method')" for="refundMethod">
                                <select id="refundMethod" wire:model="refundMethod" class="input">@foreach (['original', 'manual_cash', 'manual_bank_transfer'] as $m)<option value="{{ $m }}">{{ __('finance_admin.method_'.$m) }}</option>@endforeach</select>
                            </x-admin.field>
                        </div>
                        <p class="text-xs text-ink-500">{{ __('sales_admin.refund_dual_control_hint') }}</p>
                    </div>
                @endif
            </div>
        @endif
        @if ($showFooter)
            <x-slot:footer>
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    @if ($sel->status === 'requested')
                        <button type="button" wire:click="decide(false)" wire:loading.attr="disabled" class="btn-secondary min-h-11 text-ruby-soft">{{ __('admin.reject') }}</button>
                        <button type="button" wire:click="decide(true)" wire:loading.attr="disabled" class="btn-primary min-h-11"><x-icon name="check" class="size-4" />{{ __('sales_admin.approve_return') }}</button>
                    @elseif ($sel->status === 'approved')
                        <button type="button" wire:click="receive" wire:confirm="{{ __('sales_admin.confirm_receive_return') }}" class="btn-primary min-h-11"><x-icon name="download" class="size-4" />{{ __('sales_admin.mark_received') }}</button>
                    @elseif ($sel->status === 'received')
                        @if ($canApprove)<button type="button" wire:click="close" class="btn-secondary min-h-11">{{ __('sales_admin.close_without_refund') }}</button>@endif
                        @if ($canRefund)<button type="button" wire:click="refund" wire:loading.attr="disabled" class="btn-primary min-h-11"><x-icon name="refund" class="size-4" />{{ __('sales_admin.raise_refund') }}</button>@endif
                    @elseif ($sel->status === 'refunded')
                        <button type="button" wire:click="close" class="btn-primary min-h-11">{{ __('sales_admin.close_return') }}</button>
                    @endif
                </div>
            </x-slot:footer>
        @endif
    </x-admin.dialog>

    @include('livewire.admin.sales.partials.order-peek')
</div>
