<div>
    <x-admin.page-header :title="__('nav.purchase_orders')" :eyebrow="__('nav.inventory')" :description="__('inventory_admin.po_hint')">
        <x-slot:actions>
            @if ($this->can('suppliers.view'))<a href="{{ route('admin.suppliers.index') }}" wire:navigate class="btn-secondary"><x-icon name="building" class="size-4" />{{ __('nav.suppliers') }}</a>@endif
            @if ($branches->isNotEmpty())<button type="button" wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('inventory_admin.new_po') }}</button>@endif
        </x-slot:actions>
    </x-admin.page-header>
    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('inventory_admin.po_search')" />
            <select wire:model.live="filterStatus" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:w-48" aria-label="{{ __('admin.status') }}">
                <option value="">{{ __('admin.all_statuses') }}</option>
                <option value="open">{{ __('inventory_admin.open_only') }}</option>
                @foreach (['draft', 'pending_approval', 'approved', 'partially_received', 'received', 'closed', 'rejected', 'cancelled'] as $st)<option value="{{ $st }}">{{ __('status.'.$st) }}</option>@endforeach
            </select>
            <select wire:model.live="filterSupplier" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:w-48" aria-label="{{ __('inventory_admin.supplier') }}">
                <option value="">{{ __('inventory_admin.all_suppliers') }}</option>
                @foreach ($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
            </select>
            <x-slot:meta>{{ trans_choice('inventory_admin.po_count', $orders->total(), ['count' => $orders->total()]) }}</x-slot:meta>
        </x-admin.toolbar>
        @if ($orders->isEmpty())
            <x-admin.empty :title="__('inventory_admin.no_pos')" :description="__('inventory_admin.no_pos_hint')" icon="receipt" />
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('inventory_admin.po') }}</th><th>{{ __('inventory_admin.supplier') }}</th><th>{{ __('admin.branch') }}</th><th class="!text-end">{{ __('inventory_admin.lines') }}</th><th class="!text-end">{{ __('admin.total') }}</th><th>{{ __('inventory_admin.expected_on') }}</th><th>{{ __('admin.status') }}</th><th class="w-12"></th></tr></thead>
                <tbody>@foreach ($orders as $po)
                    <tr wire:key="po-{{ $po->id }}">
                        <td><a href="{{ route('admin.purchase-orders.show', $po) }}" wire:navigate class="font-mono font-medium hover:text-brass-600" dir="ltr">{{ $po->number }}</a>@if ($po->supplier_reference)<span class="block font-mono text-xs text-ink-500" dir="ltr">{{ $po->supplier_reference }}</span>@endif</td>
                        <td>{{ $po->supplier->name }}</td>
                        <td><span class="badge-neutral font-mono" dir="ltr">{{ $po->branch->code }}</span></td>
                        <td class="text-end tabular-nums">{{ $po->items_count }}</td>
                        <td class="whitespace-nowrap text-end font-medium tabular-nums">{{ \App\Support\Money::of((string) $po->subtotal)->format() }}</td>
                        <td class="text-xs text-ink-500 tabular-nums">{{ $po->expected_at ? \App\Support\BusinessTime::display($po->expected_at, 'd M Y') : '—' }}</td>
                        <td><x-admin.status :value="$po->status" /></td>
                        <td><a href="{{ route('admin.purchase-orders.show', $po) }}" wire:navigate class="btn-ghost btn-sm" aria-label="{{ __('admin.details') }}"><x-icon name="chevron-right" class="size-4 rtl-flip" /></a></td>
                    </tr>
                @endforeach</tbody>
            </table></div>
            @if ($orders->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $orders->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>

    <x-admin.dialog wire:model="showEditor" :title="__('inventory_admin.new_po')" :description="__('inventory_admin.new_po_hint')" max-width="max-w-4xl">
        @if ($form)
        <form wire:submit="save" class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-admin.field :label="__('inventory_admin.supplier')" for="form.supplier_id" required>
                    <select id="form.supplier_id" wire:model="form.supplier_id" class="input pe-10 rtl:[background-position:left_0.5rem_center]"><option value="">—</option>@foreach ($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }} ({{ $s->code }})</option>@endforeach</select>
                </x-admin.field>
                <x-admin.field :label="__('inventory_admin.receiving_branch')" for="form.branch_id" required>
                    <select id="form.branch_id" wire:model="form.branch_id" class="input pe-10 rtl:[background-position:left_0.5rem_center]"><option value="">—</option>@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->code }} · {{ $b->localized('name') }}</option>@endforeach</select>
                </x-admin.field>
                <x-admin.field :label="__('inventory_admin.expected_on')" for="form.expected_at"><input id="form.expected_at" type="date" wire:model="form.expected_at" class="input" dir="ltr"></x-admin.field>
                <x-admin.field :label="__('inventory_admin.supplier_reference')" for="form.supplier_reference"><input id="form.supplier_reference" wire:model="form.supplier_reference" class="input font-mono" dir="ltr"></x-admin.field>
            </div>
            <x-admin.variant-picker :results="$matches" :label="__('inventory_admin.add_products')" />
            @error('lines')<p class="field-error">{{ $message }}</p>@enderror
            @if ($lines)
                <div class="overflow-hidden rounded-xl border border-sand-200/70 dark:border-ink-700"><div class="table-wrap"><table class="table">
                    <thead><tr><th>{{ __('catalog_admin.product') }}</th><th class="w-28">{{ __('admin.quantity') }}</th><th class="w-36">{{ __('inventory_admin.unit_cost') }}</th><th class="!text-end">{{ __('inventory_admin.line_total') }}</th><th></th></tr></thead>
                    <tbody>@foreach ($lines as $i => $l)
                        @php $lt = is_numeric($l['unit_cost']) && is_numeric($l['quantity']) && preg_match('/^\d+(\.\d{1,4})?$/', (string) $l['unit_cost']) ? \App\Support\Money::of((string) $l['unit_cost'])->times((int) $l['quantity'])->format() : '—'; @endphp
                        <tr wire:key="pl-{{ $l['variant_id'] }}">
                            <td>@if ($lineVariants[$l['variant_id']] ?? null)<x-admin.variant :variant="$lineVariants[$l['variant_id']]" />@endif</td>
                            <td><input type="number" min="1" wire:model.live.debounce.400ms="lines.{{ $i }}.quantity" class="input py-2 tabular-nums" aria-label="{{ __('admin.quantity') }}">@error("lines.$i.quantity")<p class="field-error">{{ $message }}</p>@enderror</td>
                            <td><input wire:model.live.debounce.400ms="lines.{{ $i }}.unit_cost" inputmode="decimal" class="input py-2 tabular-nums" dir="ltr" placeholder="0.00" aria-label="{{ __('inventory_admin.unit_cost') }}">@error("lines.$i.unit_cost")<p class="field-error">{{ $message }}</p>@enderror</td>
                            <td class="whitespace-nowrap text-end tabular-nums">{{ $lt }}</td>
                            <td><button type="button" wire:click="removeLine({{ $i }})" class="btn-ghost btn-sm px-2 text-ruby-soft" aria-label="{{ __('org_admin.remove') }}"><x-icon name="x" class="size-4" /></button></td>
                        </tr>
                    @endforeach</tbody>
                    <tfoot><tr><td colspan="3" class="px-4 py-3 text-end text-sm font-medium">{{ __('inventory_admin.subtotal') }}</td><td class="px-4 py-3 text-end font-display text-lg font-semibold tabular-nums">{{ $draftTotal->format() }}</td><td></td></tr></tfoot>
                </table></div></div>
            @endif
            <x-admin.field :label="__('admin.notes')" for="form.notes"><textarea id="form.notes" wire:model="form.notes" rows="2" class="input"></textarea></x-admin.field>
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary" @click="open = false">{{ __('admin.cancel') }}</button>
                <button class="btn-primary" wire:loading.attr="disabled" wire:target="save"><x-icon name="check" class="size-4" />{{ __('inventory_admin.create_po') }}</button>
            </div>
        </form>
        @endif
    </x-admin.dialog>
</div>
