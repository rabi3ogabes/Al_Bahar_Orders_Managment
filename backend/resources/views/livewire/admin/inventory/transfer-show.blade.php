@php
    $s = $t->status;
    $editQty = $can['approve'] || $can['dispatch'];
    $qtyLabel = $can['approve'] ? __('inventory_admin.approve_qty') : __('inventory_admin.dispatch_qty');
@endphp
<div class="mx-auto max-w-7xl">
    <a href="{{ route('admin.transfers.index') }}" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-ink-500 hover:text-brass-600"><x-icon name="chevron-left" class="size-4 rtl-flip" />{{ __('nav.transfers') }}</a>
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <p class="eyebrow mb-2">{{ __('inventory_admin.transfer') }}</p>
            <h1 class="font-mono text-2xl font-semibold tracking-tight text-ink-900 sm:text-3xl dark:text-ivory" dir="ltr">{{ $t->number }}</h1>
            <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                <span class="font-medium">{{ $t->source->localized('name') }}</span>
                <x-icon name="arrow-right" class="size-4 text-brass-500 rtl-flip" />
                <span class="font-medium">{{ $t->destination->localized('name') }}</span>
            </div>
        </div>
        <div><x-admin.status :value="$s" /></div>
    </div>

    <div class="grid gap-6 [&>*]:min-w-0 lg:grid-cols-3">
        <div class="min-w-0 space-y-6 lg:col-span-2">
            <div class="card overflow-hidden">
                <div class="flex items-center justify-between p-5 sm:p-6">
                    <h2 class="font-display text-xl font-semibold">{{ __('inventory_admin.lines') }}</h2>
                    @if ($t->notes)<p class="max-w-sm truncate text-sm text-ink-500" title="{{ $t->notes }}"><x-icon name="message" class="me-1 inline size-3.5" />{{ $t->notes }}</p>@endif
                </div>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr>
                            <th>{{ __('catalog_admin.product') }}</th><th class="!text-end">{{ __('inventory_admin.requested') }}</th><th class="!text-end">{{ __('inventory_admin.approved') }}</th>
                            <th class="!text-end">{{ __('inventory_admin.dispatched') }}</th><th class="!text-end">{{ __('inventory_admin.received') }}</th><th class="!text-end">{{ __('inventory_admin.damaged') }}</th><th class="!text-end">{{ __('inventory_admin.in_transit') }}</th>
                            @if ($editQty)<th class="w-28">{{ $qtyLabel }}</th>@endif
                        </tr></thead>
                        <tbody>
                        @foreach ($t->items as $it)
                            <tr wire:key="ti-{{ $it->id }}">
                                <td><x-admin.variant :variant="$it->variant" />@if ($it->discrepancy_note)<span class="mt-1 block whitespace-pre-line text-xs text-amber-soft">{{ $it->discrepancy_note }}</span>@endif</td>
                                <td class="text-end tabular-nums">{{ $it->quantity_requested }}</td>
                                <td class="text-end tabular-nums {{ $it->quantity_approved < $it->quantity_requested && $t->approved_at ? 'text-amber-soft' : '' }}">{{ $t->approved_at ? $it->quantity_approved : '—' }}</td>
                                <td class="text-end tabular-nums">{{ $t->dispatched_at ? $it->quantity_dispatched : '—' }}</td>
                                <td class="text-end tabular-nums text-emerald-soft">{{ $it->quantity_received ?: '—' }}</td>
                                <td class="text-end tabular-nums {{ $it->quantity_damaged ? 'text-ruby-soft' : '' }}">{{ $it->quantity_damaged ?: '—' }}</td>
                                <td class="text-end font-semibold tabular-nums {{ $inTransit[$it->id] ? 'text-sky-soft dark:text-sky-300' : 'text-ink-400' }}">{{ $inTransit[$it->id] ?: '—' }}</td>
                                @if ($editQty)<td><input type="number" min="0" max="{{ $can['approve'] ? $it->quantity_requested : $it->quantity_approved }}" wire:model="qty.{{ $it->id }}" class="input py-2 tabular-nums" aria-label="{{ $qtyLabel }}"></td>@endif
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($can['approve'] || $can['dispatch'] || $can['submit'] || $can['reject'] || $can['cancel'] || $can['complete'] || $can['awaitingOther'])
                    <div class="flex flex-col gap-2 border-t border-sand-100 p-4 sm:flex-row sm:items-center sm:px-6 dark:border-ink-800">
                        @if ($can['awaitingOther'])<p class="text-sm text-ink-500 sm:me-auto"><x-icon name="info" class="me-1 inline size-4" />{{ __('inventory_admin.needs_second_person') }}</p>@endif
                        @if ($can['cancel'])<button type="button" wire:click="$set('confirming', 'cancel')" class="btn-ghost text-ruby-soft {{ $can['awaitingOther'] ? '' : 'sm:me-auto' }}">{{ __('inventory_admin.cancel_transfer') }}</button>@endif
                        @if ($can['reject'])<button type="button" wire:click="$set('confirming', 'reject')" class="btn-secondary">{{ __('admin.reject') }}</button>@endif
                        @if ($can['submit'])<button type="button" wire:click="submit" class="btn-primary"><x-icon name="arrow-up-right" class="size-4" />{{ __('inventory_admin.submit_for_approval') }}</button>@endif
                        @if ($can['approve'])<button type="button" wire:click="approve" wire:confirm="{{ __('inventory_admin.confirm_approve_transfer') }}" class="btn-primary" wire:loading.attr="disabled" wire:target="approve"><x-icon name="check" class="size-4" />{{ __('inventory_admin.approve_reserve') }}</button>@endif
                        @if ($can['dispatch'])<button type="button" wire:click="dispatchTransfer" wire:confirm="{{ __('inventory_admin.confirm_dispatch') }}" class="btn-primary" wire:loading.attr="disabled" wire:target="dispatchTransfer"><x-icon name="truck" class="size-4" />{{ __('inventory_admin.dispatch') }}</button>@endif
                        @if ($can['complete'])<button type="button" wire:click="complete" wire:confirm="{{ __('inventory_admin.confirm_complete') }}" class="btn-primary"><x-icon name="check" class="size-4" />{{ __('inventory_admin.complete_transfer') }}</button>@endif
                    </div>
                @endif
                @if ($confirming)
                    <form wire:submit="confirmReason" class="flex flex-col gap-2 border-t border-ruby-soft/20 bg-ruby-soft/5 p-4 sm:flex-row sm:px-6">
                        <input wire:model="reason" class="input flex-1" placeholder="{{ $confirming === 'reject' ? __('inventory_admin.reject_reason_ph') : __('inventory_admin.cancel_reason_ph') }}" aria-label="{{ __('admin.reason') }}" autofocus>
                        <div class="flex gap-2"><button type="button" wire:click="$set('confirming', null)" class="btn-secondary">{{ __('admin.back') }}</button><button class="btn-danger">{{ $confirming === 'reject' ? __('admin.reject') : __('inventory_admin.cancel_transfer') }}</button></div>
                        @error('reason')<p class="field-error w-full">{{ $message }}</p>@enderror
                    </form>
                @endif
            </div>

            @if ($can['receive'])
                <form wire:submit="receive" class="card overflow-hidden">
                    <div class="p-5 sm:p-6"><h2 class="font-display text-xl font-semibold">{{ __('inventory_admin.receive_goods') }}</h2><p class="mt-1 text-sm text-ink-500">{{ __('inventory_admin.receive_hint') }}</p></div>
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>{{ __('catalog_admin.product') }}</th><th class="!text-end">{{ __('inventory_admin.in_transit') }}</th><th class="w-28">{{ __('inventory_admin.received') }}</th><th class="w-28">{{ __('inventory_admin.damaged') }}</th><th>{{ __('admin.notes') }}</th></tr></thead>
                        <tbody>@foreach ($t->items as $it) @continue(! $inTransit[$it->id])
                            <tr wire:key="rc-{{ $it->id }}">
                                <td><x-admin.variant :variant="$it->variant" /></td>
                                <td class="text-end font-semibold tabular-nums">{{ $inTransit[$it->id] }}</td>
                                <td><input type="number" min="0" max="{{ $inTransit[$it->id] }}" wire:model="receipt.{{ $it->id }}.received" class="input py-2 tabular-nums" aria-label="{{ __('inventory_admin.received') }}"></td>
                                <td><input type="number" min="0" max="{{ $inTransit[$it->id] }}" wire:model="receipt.{{ $it->id }}.damaged" class="input py-2 tabular-nums" aria-label="{{ __('inventory_admin.damaged') }}"></td>
                                <td><input wire:model="receipt.{{ $it->id }}.note" class="input py-2 text-xs" placeholder="{{ __('inventory_admin.discrepancy_ph') }}" aria-label="{{ __('admin.notes') }}"></td>
                            </tr>
                        @endforeach</tbody>
                    </table></div>
                    <div class="flex justify-end border-t border-sand-100 p-4 sm:px-6 dark:border-ink-800"><button class="btn-primary" wire:loading.attr="disabled" wire:target="receive"><x-icon name="download" class="size-4" />{{ __('inventory_admin.record_receipt') }}</button></div>
                </form>
            @endif
        </div>

        <div class="card card-pad h-fit">
            <h2 class="mb-5 font-display text-xl font-semibold">{{ __('inventory_admin.timeline') }}</h2>
            <x-admin.timeline :steps="$steps" />
        </div>
    </div>
</div>
