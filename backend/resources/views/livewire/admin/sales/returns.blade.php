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
                    <tr wire:key="rr-{{ $rr->id }}" class="cursor-pointer" wire:click="select('{{ $rr->id }}')">
                        <td><span class="font-mono font-medium" dir="ltr">{{ $rr->number }}</span><span class="block text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($rr->created_at) }}</span></td>
                        <td><a href="{{ route('admin.orders.show', $rr->order_id) }}" wire:navigate @click.stop class="font-mono text-sm hover:text-brass-600" dir="ltr">{{ $rr->order->number }}</a></td>
                        <td class="max-w-[12rem] truncate">{{ $rr->order->customer_name }}</td>
                        <td><span class="badge-neutral">{{ __('sales_admin.reason_'.$rr->reason_code) }}</span></td>
                        <td class="text-end tabular-nums">{{ collect($rr->items)->sum('quantity') }}</td>
                        <td><x-admin.status :value="$rr->status" /></td>
                        <td><button type="button" class="btn-ghost btn-sm" aria-label="{{ __('admin.details') }}"><x-icon name="chevron-right" class="size-4 rtl-flip" /></button></td>
                    </tr>
                @endforeach</tbody>
            </table></div>
            @if ($returns->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $returns->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>

    <x-admin.dialog wire:model="showDetail" :title="$sel ? __('sales_admin.return').' '.$sel->number : ''" :description="$sel ? __('sales_admin.return_for', ['order' => $sel->order->number, 'name' => $sel->order->customer_name]) : null" max-width="max-w-2xl">
        @if ($sel)
            <div class="space-y-5">
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <x-admin.status :value="$sel->status" />
                    <span class="badge-neutral">{{ __('sales_admin.reason_'.$sel->reason_code) }}</span>
                    @if ($refund)<a href="{{ route('admin.refunds.index', ['search' => $refund->number]) }}" wire:navigate class="badge-info">{{ $refund->number }} · {{ __('status.'.$refund->status) }}</a>@endif
                </div>
                @if ($sel->reason)<blockquote class="rounded-xl bg-sand-50 p-3.5 text-sm text-ink-700 dark:bg-ink-800/60 dark:text-sand-100">“{{ $sel->reason }}”</blockquote>@endif
                <div class="overflow-hidden rounded-xl border border-sand-200/70 dark:border-ink-700"><table class="table">
                    <thead><tr><th>{{ __('sales_admin.item') }}</th><th class="!text-end">{{ __('admin.quantity') }}</th><th class="!text-end">{{ __('sales_admin.unit_price') }}</th></tr></thead>
                    <tbody>@foreach ($sel->items as $line)
                        @php $it = $items[$line['order_item_id']] ?? null; @endphp
                        <tr><td>@if ($it)<span class="block font-medium">{{ $it->name() }}</span><span class="font-mono text-xs text-ink-500" dir="ltr">{{ $it->sku }}</span>@else — @endif</td>
                            <td class="text-end tabular-nums">{{ $line['quantity'] }}</td><td class="text-end">@if ($it)<x-admin.money :value="$it->unit_final_price" />@endif</td></tr>
                    @endforeach</tbody>
                </table></div>

                @if ($sel->status === 'requested' && $this->can('orders.approve', $sel->branch_id))
                    <div class="space-y-3 border-t border-sand-100 pt-5 dark:border-ink-800">
                        <x-admin.field :label="__('sales_admin.decision_note')" for="note" :hint="__('sales_admin.reject_needs_reason')"><textarea id="note" wire:model="note" rows="2" class="input"></textarea></x-admin.field>
                        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <button type="button" wire:click="decide(false)" class="btn-secondary text-ruby-soft">{{ __('admin.reject') }}</button>
                            <button type="button" wire:click="decide(true)" class="btn-primary"><x-icon name="check" class="size-4" />{{ __('sales_admin.approve_return') }}</button>
                        </div>
                    </div>
                @elseif ($sel->status === 'approved' && $this->can('orders.edit', $sel->branch_id))
                    <div class="space-y-3 border-t border-sand-100 pt-5 dark:border-ink-800">
                        <x-admin.toggle :label="__('sales_admin.restock_items')" :hint="__('sales_admin.restock_hint')" wire:model="restock" />
                        <div class="flex justify-end"><button type="button" wire:click="receive" wire:confirm="{{ __('sales_admin.confirm_receive_return') }}" class="btn-primary"><x-icon name="download" class="size-4" />{{ __('sales_admin.mark_received') }}</button></div>
                    </div>
                @elseif ($sel->status === 'received')
                    <div class="space-y-3 border-t border-sand-100 pt-5 dark:border-ink-800">
                        @if ($this->can('refunds.create', $sel->branch_id))
                            <h3 class="font-semibold">{{ __('sales_admin.raise_refund') }}</h3>
                            <div class="grid gap-3 sm:grid-cols-2 [&>*]:min-w-0">
                                <x-admin.field :label="__('finance_admin.amount')" for="refundAmount" required><input id="refundAmount" type="number" step="0.01" min="0.01" wire:model="refundAmount" class="input tabular-nums" dir="ltr"></x-admin.field>
                                <x-admin.field :label="__('finance_admin.method')" for="refundMethod">
                                    <select id="refundMethod" wire:model="refundMethod" class="input">@foreach (['original', 'manual_cash', 'manual_bank_transfer'] as $m)<option value="{{ $m }}">{{ __('finance_admin.method_'.$m) }}</option>@endforeach</select>
                                </x-admin.field>
                            </div>
                            <p class="text-xs text-ink-500">{{ __('sales_admin.refund_dual_control_hint') }}</p>
                        @endif
                        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            @if ($this->can('orders.approve', $sel->branch_id))<button type="button" wire:click="close" class="btn-secondary">{{ __('sales_admin.close_without_refund') }}</button>@endif
                            @if ($this->can('refunds.create', $sel->branch_id))<button type="button" wire:click="refund" class="btn-primary"><x-icon name="refund" class="size-4" />{{ __('sales_admin.raise_refund') }}</button>@endif
                        </div>
                    </div>
                @elseif ($sel->status === 'refunded' && $this->can('orders.approve', $sel->branch_id))
                    <div class="flex justify-end border-t border-sand-100 pt-5 dark:border-ink-800"><button type="button" wire:click="close" class="btn-primary">{{ __('sales_admin.close_return') }}</button></div>
                @endif
            </div>
        @endif
    </x-admin.dialog>
</div>
