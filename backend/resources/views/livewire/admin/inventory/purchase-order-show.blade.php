<div class="{{ $embedded ? '' : 'mx-auto max-w-7xl' }}">
    @if ($embedded)
        <div class="mb-5 flex flex-wrap items-center gap-2 text-sm text-ink-500">
            <x-admin.status :value="$po->status" />
            <span class="badge-neutral tabular-nums">{{ \App\Support\Money::of((string) $po->subtotal)->format() }}</span>
            <span>{{ __('inventory_admin.deliver_to', ['branch' => $po->branch->localized('name')]) }}@if ($po->expected_at) · {{ __('inventory_admin.expected_on') }} {{ \App\Support\BusinessTime::display($po->expected_at, 'd M Y') }}@endif</span>
        </div>
    @else
    <a href="{{ route('admin.purchase-orders.index') }}" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-ink-500 hover:text-brass-600"><x-icon name="chevron-left" class="size-4 rtl-flip" />{{ __('nav.purchase_orders') }}</a>
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <p class="eyebrow mb-2">{{ $po->supplier->name }}</p>
            <h1 class="font-mono text-2xl font-semibold tracking-tight sm:text-3xl" dir="ltr">{{ $po->number }}</h1>
            <p class="mt-2 text-sm text-ink-500">{{ __('inventory_admin.deliver_to', ['branch' => $po->branch->localized('name')]) }}@if ($po->expected_at) · {{ __('inventory_admin.expected_on') }} {{ \App\Support\BusinessTime::display($po->expected_at, 'd M Y') }}@endif</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <x-admin.status :value="$po->status" />
            <span class="badge-neutral tabular-nums">{{ \App\Support\Money::of((string) $po->subtotal)->format() }}</span>
        </div>
    </div>
    @endif

    <div class="grid gap-6 [&>*]:min-w-0 {{ $embedded ? '' : 'lg:grid-cols-3' }}">
        <div class="min-w-0 space-y-6 {{ $embedded ? '' : 'lg:col-span-2' }}">
            <div class="card overflow-hidden">
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>{{ __('catalog_admin.product') }}</th><th class="!text-end">{{ __('inventory_admin.ordered') }}</th><th class="!text-end">{{ __('inventory_admin.received') }}</th><th class="!text-end">{{ __('inventory_admin.outstanding') }}</th><th class="!text-end">{{ __('inventory_admin.unit_cost') }}</th><th class="!text-end">{{ __('inventory_admin.line_total') }}</th></tr></thead>
                    <tbody>@foreach ($po->items as $it)
                        @php $out = max(0, $it->quantity_ordered - $it->quantity_received); @endphp
                        <tr wire:key="poi-{{ $it->id }}">
                            <td><x-admin.variant :variant="$it->variant" /></td>
                            <td class="text-end tabular-nums">{{ $it->quantity_ordered }}</td>
                            <td class="text-end tabular-nums text-emerald-soft">{{ $it->quantity_received ?: '—' }}</td>
                            <td class="text-end font-semibold tabular-nums {{ $out ? 'text-amber-soft' : 'text-ink-400' }}">{{ $out ?: '—' }}</td>
                            <td class="whitespace-nowrap text-end text-xs tabular-nums">{{ \App\Support\Money::of((string) $it->unit_cost)->format() }}</td>
                            <td class="whitespace-nowrap text-end tabular-nums">{{ \App\Support\Money::of((string) $it->line_total)->format() }}</td>
                        </tr>
                    @endforeach</tbody>
                    <tfoot><tr><td colspan="5" class="px-4 py-3 text-end text-sm font-medium">{{ __('inventory_admin.subtotal') }}</td><td class="px-4 py-3 text-end font-display text-lg font-semibold tabular-nums">{{ \App\Support\Money::of((string) $po->subtotal)->format() }}</td></tr></tfoot>
                </table></div>
                @if (collect($can)->except('receive')->filter()->isNotEmpty())
                    <div class="flex flex-col gap-2 border-t border-sand-100 p-4 sm:flex-row sm:items-center sm:px-6 dark:border-ink-800">
                        @if ($can['awaitingOther'])<p class="text-sm text-ink-500 sm:me-auto"><x-icon name="info" class="me-1 inline size-4" />{{ __('inventory_admin.needs_second_person') }}</p>@endif
                        @if ($can['cancel'])<button type="button" wire:click="cancel" wire:confirm="{{ __('inventory_admin.confirm_cancel_po') }}" class="btn-ghost text-ruby-soft {{ $can['awaitingOther'] ? '' : 'sm:me-auto' }}">{{ __('inventory_admin.cancel_po') }}</button>@endif
                        @if ($can['reject'])<button type="button" wire:click="reject" wire:confirm="{{ __('inventory_admin.confirm_reject_po') }}" class="btn-secondary">{{ __('admin.reject') }}</button>@endif
                        @if ($can['submit'])<button type="button" wire:click="submit" class="btn-primary"><x-icon name="arrow-up-right" class="size-4" />{{ __('inventory_admin.submit_for_approval') }}</button>@endif
                        @if ($can['approve'])<button type="button" wire:click="approve" wire:confirm="{{ __('inventory_admin.confirm_approve_po') }}" class="btn-primary"><x-icon name="check" class="size-4" />{{ __('admin.approve') }}</button>@endif
                        @if ($can['close'])<button type="button" wire:click="close" wire:confirm="{{ __('inventory_admin.confirm_close_po') }}" class="btn-primary"><x-icon name="lock" class="size-4" />{{ __('inventory_admin.close_po') }}</button>@endif
                    </div>
                @endif
            </div>

            @if ($can['receive'])
                <form wire:submit="receive" class="card overflow-hidden">
                    <div class="p-5 sm:p-6"><h2 class="font-display text-xl font-semibold">{{ __('inventory_admin.goods_receipt') }}</h2><p class="mt-1 text-sm text-ink-500">{{ __('inventory_admin.goods_receipt_hint') }}</p></div>
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>{{ __('catalog_admin.product') }}</th><th class="!text-end">{{ __('inventory_admin.outstanding') }}</th><th class="w-24">{{ __('inventory_admin.received') }}</th><th class="w-24">{{ __('inventory_admin.rejected_qty') }}</th><th class="w-32">{{ __('inventory_admin.unit_cost') }}</th><th>{{ __('inventory_admin.rejection_reason') }}</th></tr></thead>
                        <tbody>@foreach ($po->items as $it) @php $out = max(0, $it->quantity_ordered - $it->quantity_received); @endphp @continue(! $out)
                            <tr wire:key="gr-{{ $it->id }}">
                                <td><x-admin.variant :variant="$it->variant" /></td>
                                <td class="text-end font-semibold tabular-nums">{{ $out }}</td>
                                <td><input type="number" min="0" max="{{ $out }}" wire:model="receipt.{{ $it->id }}.received" class="input py-2 tabular-nums" aria-label="{{ __('inventory_admin.received') }}"></td>
                                <td><input type="number" min="0" wire:model="receipt.{{ $it->id }}.rejected" class="input py-2 tabular-nums" aria-label="{{ __('inventory_admin.rejected_qty') }}"></td>
                                <td><input wire:model="receipt.{{ $it->id }}.unit_cost" inputmode="decimal" class="input py-2 tabular-nums" dir="ltr" aria-label="{{ __('inventory_admin.unit_cost') }}">@error("receipt.{$it->id}.unit_cost")<p class="field-error">{{ $message }}</p>@enderror</td>
                                <td><input wire:model="receipt.{{ $it->id }}.rejection_reason" class="input py-2 text-xs" placeholder="{{ __('inventory_admin.rejection_ph') }}" aria-label="{{ __('inventory_admin.rejection_reason') }}">@error("receipt.{$it->id}.rejection_reason")<p class="field-error">{{ $message }}</p>@enderror</td>
                            </tr>
                        @endforeach</tbody>
                    </table></div>
                    <div class="grid gap-4 border-t border-sand-100 p-5 sm:grid-cols-2 sm:px-6 dark:border-ink-800">
                        <x-admin.field :label="__('inventory_admin.supplier_invoice')" for="invoice"><input id="invoice" wire:model="invoice" class="input font-mono" dir="ltr"></x-admin.field>
                        <x-admin.field :label="__('admin.notes')" for="receiptNotes"><input id="receiptNotes" wire:model="receiptNotes" class="input"></x-admin.field>
                    </div>
                    <div class="flex justify-end border-t border-sand-100 p-4 sm:px-6 dark:border-ink-800"><button class="btn-primary" wire:confirm="{{ __('inventory_admin.confirm_receive') }}" wire:loading.attr="disabled" wire:target="receive"><x-icon name="download" class="size-4" />{{ __('inventory_admin.record_receipt') }}</button></div>
                </form>
            @endif

            @if ($receipts->isNotEmpty())
                <div class="card overflow-hidden">
                    <div class="p-5 sm:p-6"><h2 class="font-display text-xl font-semibold">{{ __('inventory_admin.receipts') }}</h2></div>
                    @foreach ($receipts as $r)
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-sand-100 px-5 py-3.5 text-sm sm:px-6 dark:border-ink-800" wire:key="grn-{{ $r->id }}">
                            <span class="font-mono font-medium" dir="ltr">{{ $r->number }}</span>
                            <span class="text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($r->received_at) }}</span>
                            <span class="text-ink-500">{{ $people[$r->received_by] ?? '' }}</span>
                            <span class="badge-success tabular-nums">+{{ $r->items->sum('quantity_received') }}</span>
                            @if ($r->items->sum('quantity_rejected'))<span class="badge-danger tabular-nums">{{ __('inventory_admin.rejected_n', ['n' => $r->items->sum('quantity_rejected')]) }}</span>@endif
                            @if ($r->supplier_invoice_number)<span class="ms-auto text-xs text-ink-500">{{ __('inventory_admin.invoice') }} <span class="font-mono" dir="ltr">{{ $r->supplier_invoice_number }}</span></span>@endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
        <div class="space-y-6">
            <div class="card card-pad"><h2 class="mb-5 font-display text-xl font-semibold">{{ __('inventory_admin.timeline') }}</h2><x-admin.timeline :steps="$steps" /></div>
            <div class="card card-pad space-y-3 text-sm">
                <h2 class="font-display text-lg font-semibold">{{ __('inventory_admin.supplier') }}</h2>
                <p class="font-medium">{{ $po->supplier->name }} <span class="font-mono text-xs text-ink-400" dir="ltr">{{ $po->supplier->code }}</span></p>
                @if ($po->supplier->contact_name)<p class="text-ink-600">{{ $po->supplier->contact_name }}</p>@endif
                @if ($po->supplier->phone)<p class="text-ink-600" dir="ltr">{{ $po->supplier->phone }}</p>@endif
                @if ($po->supplier->email)<p class="text-ink-600" dir="ltr">{{ $po->supplier->email }}</p>@endif
                @if ($po->supplier_reference)<p class="text-xs text-ink-500">{{ __('inventory_admin.supplier_reference') }}: <span class="font-mono" dir="ltr">{{ $po->supplier_reference }}</span></p>@endif
                @if ($po->notes)<p class="border-t border-sand-100 pt-3 text-ink-600 dark:border-ink-800">{{ $po->notes }}</p>@endif
            </div>
        </div>
    </div>
</div>
