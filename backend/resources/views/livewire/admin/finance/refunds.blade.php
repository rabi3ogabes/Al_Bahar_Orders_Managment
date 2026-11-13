@php
    $tabs = [
        'requested' => ['clock', __('finance_admin.tab_requested'), $counts['requested']],
        'to_process' => ['refund', __('finance_admin.tab_to_process'), $counts['to_process']],
        'processed' => ['check', __('finance_admin.tab_processed')],
        'closed' => ['x', __('finance_admin.tab_closed')],
    ];
@endphp
<div>
    <x-admin.page-header :title="__('nav.refunds')" :eyebrow="__('nav.finance')" :description="__('finance_admin.refunds_hint')" />
    <x-admin.tabs :tabs="$tabs" :current="$tab" />

    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('finance_admin.refunds_search')" />
            <x-slot:meta>{{ trans_choice('finance_admin.refunds_count', $rows->total(), ['count' => $rows->total()]) }}</x-slot:meta>
        </x-admin.toolbar>
        @if ($rows->isEmpty())
            <x-admin.empty :title="__('finance_admin.refunds_empty_'.$tab)" :description="__('finance_admin.refunds_empty_hint')" icon="refund" />
        @else
            <ul class="divide-y divide-sand-100 dark:divide-ink-800">
                @foreach ($rows as $r)
                    @php
                        $branches = $r->order ? $r->order->fulfillments->pluck('branch_id')->unique()->all() : [];
                        $allowed = fn ($perm) => $branches ? collect($branches)->every(fn ($b) => $this->can($perm, $b)) : $this->can($perm);
                        $canOrder = $branches ? collect($branches)->contains(fn ($b) => $this->can('orders.view', $b)) : $this->can('orders.view');
                        $own = $r->requested_by === $me->id && ! $me->isSuperAdmin();
                        $manual = $r->method !== 'original';
                    @endphp
                    <li class="flex flex-col gap-3 p-4 lg:flex-row lg:items-center sm:px-6" wire:key="rf-{{ $r->id }}">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono font-medium" dir="ltr">{{ $r->number }}</span>
                                <x-admin.status :value="$r->status" />
                                <span class="badge-neutral">{{ __('finance_admin.method_'.$r->method) }}</span>
                                @if ($r->order)<x-admin.order-peek-link :id="$r->order_id" :number="$r->order->number" :can="$canOrder" class="text-xs text-ink-500" />@endif
                            </div>
                            <p class="mt-1 text-sm text-ink-600 dark:text-sand-200">{{ __('finance_admin.rrc_'.$r->reason_code) }}@if ($r->reason) — <span class="text-ink-500">{{ \Illuminate\Support\Str::limit($r->reason, 90) }}</span>@endif</p>
                            <p class="mt-0.5 text-xs text-ink-500">
                                {{ __('finance_admin.requested_by', ['name' => $r->requester?->name ?? __('sales_admin.system'), 'at' => \App\Support\BusinessTime::display($r->created_at)]) }}
                                @if ($r->approved_by) · {{ __($r->status === 'rejected' ? 'finance_admin.rejected_by' : 'finance_admin.approved_by', ['name' => $approvers[$r->approved_by] ?? '—']) }}@endif
                                @if ($r->provider_reference) · <span class="font-mono" dir="ltr">{{ $r->provider_reference }}</span>@endif
                                @if ($r->failure_reason) · <span class="text-ruby-soft">{{ $r->failure_reason }}</span>@endif
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                            <x-admin.money :value="$r->amount" class="me-2 text-lg font-semibold" />
                            @if ($r->status === 'requested' && $allowed('refunds.approve'))
                                <button type="button" wire:click="open('reject', '{{ $r->id }}')" class="btn-ghost btn-sm text-ruby-soft">{{ __('admin.reject') }}</button>
                                @if ($own)
                                    <span class="badge-neutral" title="{{ __('finance_admin.needs_second_person') }}"><x-icon name="lock" class="size-3" />{{ __('finance_admin.awaiting_second_approver') }}</span>
                                @else
                                    <button type="button" wire:click="approve('{{ $r->id }}')" wire:confirm="{{ $manual ? __('finance_admin.confirm_approve_manual') : __('finance_admin.confirm_approve_gateway') }}" class="btn-primary btn-sm"><x-icon name="check" class="size-4" />{{ __('admin.approve') }}</button>
                                @endif
                            @elseif (in_array($r->status, ['approved', 'processing'], true) && $manual && $allowed('refunds.refund'))
                                <button type="button" wire:click="open('process', '{{ $r->id }}')" class="btn-primary btn-sm"><x-icon name="check" class="size-4" />{{ __('finance_admin.mark_processed') }}</button>
                            @elseif ($r->status === 'processing' && ! $manual)
                                <span class="text-xs text-ink-500">{{ __('finance_admin.awaiting_gateway') }}</span>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
            @if ($rows->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $rows->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>

    @include('livewire.admin.sales.partials.order-peek')

    {{-- Rejecting is a decision (centred modal); recording a manual payout is a form (side window). --}}
    <x-admin.dialog wire:model="showDialog" :variant="$dialog === 'reject' ? 'modal' : 'drawer'" :title="$dialog === 'reject' ? __('drawer_sales.q_reject_refund') : __('finance_admin.mark_processed')" :description="$dialog === 'process' ? __('finance_admin.process_hint') : null">
        <form wire:submit="confirmDialog" class="space-y-5">
            @if ($dialog === 'reject')
                <x-admin.field :label="__('admin.reason')" for="note" required><textarea id="note" wire:model="note" rows="3" class="input"></textarea></x-admin.field>
            @else
                <x-admin.field :label="__('finance_admin.bank_reference')" for="reference" required :hint="__('finance_admin.bank_reference_hint')"><input id="reference" wire:model="reference" class="input font-mono" dir="ltr" autocomplete="off"></x-admin.field>
            @endif
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary min-h-11" @click="open = false">{{ __('admin.cancel') }}</button>
                <button class="{{ $dialog === 'reject' ? 'btn-danger' : 'btn-primary' }} min-h-11" wire:loading.attr="disabled" wire:target="confirmDialog">{{ $dialog === 'reject' ? __('finance_admin.reject_refund') : __('finance_admin.mark_processed') }}</button>
            </div>
        </form>
    </x-admin.dialog>
</div>
