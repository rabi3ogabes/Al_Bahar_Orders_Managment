<div>
    <x-admin.page-header :title="__('nav.suppliers')" :eyebrow="__('nav.inventory')" :description="__('inventory_admin.suppliers_hint')">
        <x-slot:actions>
            @if ($canCreate)<button type="button" wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('inventory_admin.new_supplier') }}</button>@endif
        </x-slot:actions>
    </x-admin.page-header>
    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('inventory_admin.suppliers_search')" />
            <select wire:model.live="filterStatus" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:w-44" aria-label="{{ __('admin.status') }}">
                <option value="">{{ __('admin.all_statuses') }}</option><option value="active">{{ __('status.active') }}</option><option value="inactive">{{ __('status.inactive') }}</option>
            </select>
            <x-slot:meta>{{ trans_choice('inventory_admin.suppliers_count', $suppliers->total(), ['count' => $suppliers->total()]) }}</x-slot:meta>
        </x-admin.toolbar>
        @if ($suppliers->isEmpty())
            <x-admin.empty :title="__('inventory_admin.no_suppliers')" :description="__('inventory_admin.no_suppliers_hint')" icon="building">
                @if ($canCreate)<button type="button" wire:click="create" class="btn-brass"><x-icon name="plus" class="size-4" />{{ __('inventory_admin.new_supplier') }}</button>@endif
            </x-admin.empty>
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('inventory_admin.supplier') }}</th><th>{{ __('inventory_admin.contact') }}</th><th>{{ __('inventory_admin.payment_terms') }}</th><th class="!text-end">{{ __('inventory_admin.open_pos') }}</th><th>{{ __('admin.status') }}</th><th class="w-24"></th></tr></thead>
                <tbody>@foreach ($suppliers as $s)
                    <tr wire:key="sup-{{ $s->id }}">
                        <td>
                            <div class="flex items-center gap-3">
                                <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-sand-100 text-ink-600 dark:bg-ink-800 dark:text-sand-200"><x-icon name="building" class="size-4" /></span>
                                <span class="min-w-0"><span class="block truncate font-medium text-ink-900 dark:text-ivory">{{ $s->name }}</span><span class="block font-mono text-xs text-ink-500" dir="ltr">{{ $s->code }}@if ($s->country) · {{ $s->country }}@endif</span></span>
                            </div>
                        </td>
                        <td class="text-sm">
                            @if ($s->contact_name)<span class="block">{{ $s->contact_name }}</span>@endif
                            @if ($s->phone)<span class="block text-xs text-ink-500" dir="ltr">{{ $s->phone }}</span>@endif
                            @if ($s->email)<span class="block text-xs text-ink-500" dir="ltr">{{ $s->email }}</span>@endif
                            @if (! $s->contact_name && ! $s->phone && ! $s->email)<span class="text-ink-400">—</span>@endif
                        </td>
                        <td class="text-sm text-ink-600">{{ $s->payment_terms ?: '—' }}</td>
                        <td class="text-end tabular-nums"><a href="{{ route('admin.purchase-orders.index', ['filterSupplier' => $s->id, 'filterStatus' => 'open']) }}" wire:navigate class="hover:text-brass-600">{{ $s->open_po_count }}</a></td>
                        <td><x-admin.status :value="$s->status" /></td>
                        <td><div class="flex justify-end gap-1">
                            @if ($canEdit)<button type="button" wire:click="edit('{{ $s->id }}')" class="btn-ghost btn-sm px-2" aria-label="{{ __('admin.edit') }}"><x-icon name="edit" class="size-4" /></button>@endif
                            @if ($canDelete)<button type="button" wire:click="delete('{{ $s->id }}')" wire:confirm="{{ __('inventory_admin.confirm_delete_supplier') }}" class="btn-ghost btn-sm px-2 text-ruby-soft" aria-label="{{ __('admin.delete') }}"><x-icon name="trash" class="size-4" /></button>@endif
                        </div></td>
                    </tr>
                @endforeach</tbody>
            </table></div>
            @if ($suppliers->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $suppliers->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>

    <x-admin.dialog wire:model="showEditor" :title="$editingId ? __('inventory_admin.edit_supplier') : __('inventory_admin.new_supplier')" max-width="max-w-2xl">
        <form wire:submit="save" class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-admin.field :label="__('org_admin.code')" for="form.code" required><input id="form.code" wire:model="form.code" class="input font-mono uppercase" dir="ltr"></x-admin.field>
                <x-admin.field :label="__('inventory_admin.supplier_name')" for="form.name" required class="sm:col-span-2"><input id="form.name" wire:model="form.name" class="input"></x-admin.field>
                <x-admin.field :label="__('inventory_admin.contact_name')" for="form.contact_name"><input id="form.contact_name" wire:model="form.contact_name" class="input"></x-admin.field>
                <x-admin.field :label="__('org_admin.phone')" for="form.phone"><input id="form.phone" wire:model="form.phone" class="input" dir="ltr"></x-admin.field>
                <x-admin.field :label="__('admin.email')" for="form.email"><input id="form.email" type="email" wire:model="form.email" class="input" dir="ltr"></x-admin.field>
            </div>
            <x-admin.field :label="__('inventory_admin.address')" for="form.address"><input id="form.address" wire:model="form.address" class="input"></x-admin.field>
            <div class="grid gap-4 sm:grid-cols-4">
                <x-admin.field :label="__('inventory_admin.country')" for="form.country"><input id="form.country" wire:model="form.country" maxlength="2" class="input font-mono uppercase" dir="ltr" placeholder="QA"></x-admin.field>
                <x-admin.field :label="__('inventory_admin.currency')" for="form.currency"><input id="form.currency" wire:model="form.currency" maxlength="3" class="input font-mono uppercase" dir="ltr"></x-admin.field>
                <x-admin.field :label="__('inventory_admin.tax_number')" for="form.tax_number" class="sm:col-span-2"><input id="form.tax_number" wire:model="form.tax_number" class="input font-mono" dir="ltr"></x-admin.field>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-admin.field :label="__('inventory_admin.payment_terms')" for="form.payment_terms"><input id="form.payment_terms" wire:model="form.payment_terms" class="input" placeholder="{{ __('inventory_admin.payment_terms_ph') }}"></x-admin.field>
                <x-admin.field :label="__('admin.status')" for="form.status"><select id="form.status" wire:model="form.status" class="input pe-10 rtl:[background-position:left_0.5rem_center]"><option value="active">{{ __('status.active') }}</option><option value="inactive">{{ __('status.inactive') }}</option></select></x-admin.field>
            </div>
            <x-admin.field :label="__('admin.notes')" for="form.notes"><textarea id="form.notes" wire:model="form.notes" rows="2" class="input"></textarea></x-admin.field>
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary" @click="requestClose()">{{ __('admin.cancel') }}</button>
                <button class="btn-primary" wire:loading.attr="disabled" wire:target="save"><x-icon name="check" class="size-4" />{{ __('admin.save') }}</button>
            </div>
        </form>
    </x-admin.dialog>
</div>
