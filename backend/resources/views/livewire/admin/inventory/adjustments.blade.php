@php $typeIcons = ['adjustment' => 'scale', 'damaged' => 'alert', 'lost' => 'ban', 'opening_balance' => 'package', 'customer_return' => 'refund']; @endphp
<div>
    <x-admin.page-header :title="__('nav.adjustments')" :eyebrow="__('nav.inventory')" :description="__('inventory_admin.adjustments_hint')">
        <x-slot:actions>
            @if ($this->can('inventory.create'))<button type="button" wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('inventory_admin.new_adjustment') }}</button>@endif
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.tabs model="view" :current="$view" :tabs="['pending' => ['clock', __('inventory_admin.awaiting_approval'), $pendingCount ?: null], 'all' => ['history', __('inventory_admin.all_adjustments')]]" />

    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('inventory_admin.adjustments_search')" />
            @if ($filterBranches->count() > 1)
                <select wire:model.live="branch" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:w-48" aria-label="{{ __('admin.branch') }}">
                    <option value="">{{ __('admin.all_my_branches') }}</option>
                    @foreach ($filterBranches as $b)<option value="{{ $b->id }}">{{ $b->localized('name') }}</option>@endforeach
                </select>
            @endif
        </x-admin.toolbar>

        @if ($adjustments->isEmpty())
            <x-admin.empty :title="$view === 'pending' ? __('inventory_admin.no_pending') : __('inventory_admin.no_adjustments')" :description="$view === 'pending' ? __('inventory_admin.no_pending_hint') : null" icon="scale" />
        @else
            <ul class="divide-y divide-sand-100 dark:divide-ink-800">
                @foreach ($adjustments as $a)
                    @php
                        $mine = $a->created_by === auth()->id();
                        $canApprove = $a->status === 'pending_approval' && $approveScope->contains($a->branch_id) && (! $mine || auth()->user()->isSuperAdmin());
                        $units = $a->items->sum('quantity');
                    @endphp
                    <li wire:key="adj-{{ $a->id }}">
                        <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:px-6">
                            <button type="button" wire:click="$set('expanded', '{{ $expanded === $a->id ? '' : $a->id }}')" class="flex min-w-0 flex-1 items-center gap-4 text-start">
                                <span class="grid size-10 shrink-0 place-items-center rounded-xl {{ in_array($a->type, ['damaged', 'lost']) ? 'bg-ruby-soft/10 text-ruby-soft' : 'bg-sand-100 text-ink-600 dark:bg-ink-800 dark:text-sand-200' }}"><x-icon :name="$typeIcons[$a->type] ?? 'scale'" class="size-4" /></span>
                                <span class="min-w-0">
                                    <span class="flex flex-wrap items-center gap-2"><span class="font-mono text-sm font-medium" dir="ltr">{{ $a->number }}</span><span class="badge-neutral">{{ __('inventory_admin.type.'.$a->type) }}</span><span class="badge-neutral font-mono" dir="ltr">{{ $a->branch->code }}</span></span>
                                    <span class="mt-0.5 block truncate text-sm text-ink-600 dark:text-sand-200">{{ $a->reason }}</span>
                                    <span class="block text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($a->created_at) }} · {{ $people[$a->created_by] ?? '—' }} · {{ trans_choice('inventory_admin.lines_count', $a->items->count(), ['count' => $a->items->count()]) }} · <span dir="ltr">{{ $units > 0 ? '+' : '' }}{{ $units }}</span></span>
                                </span>
                            </button>
                            <div class="flex shrink-0 flex-wrap items-center gap-2">
                                <x-admin.status :value="$a->status" />
                                @if ($a->status === 'pending_approval')
                                    @if ($canApprove)
                                        <button type="button" wire:click="startReject('{{ $a->id }}')" class="btn-ghost btn-sm text-ruby-soft">{{ __('admin.reject') }}</button>
                                        <button type="button" wire:click="approve('{{ $a->id }}')" wire:confirm="{{ __('inventory_admin.confirm_approve_adjustment') }}" class="btn-primary btn-sm"><x-icon name="check" class="size-3.5" />{{ __('inventory_admin.approve_post') }}</button>
                                    @elseif ($mine)
                                        <span class="text-xs text-ink-500">{{ __('inventory_admin.needs_second_person') }}</span>
                                    @endif
                                @elseif ($a->approved_by)
                                    <span class="text-xs text-ink-500">{{ $people[$a->approved_by] ?? '' }}</span>
                                @endif
                            </div>
                        </div>
                        @if ($rejecting === $a->id)
                            <form wire:submit="reject" class="mx-5 mb-4 flex flex-col gap-2 rounded-xl border border-ruby-soft/20 bg-ruby-soft/5 p-3 sm:mx-6 sm:flex-row">
                                <input wire:model="rejectReason" class="input flex-1" placeholder="{{ __('inventory_admin.reject_reason_ph') }}" aria-label="{{ __('admin.reason') }}" autofocus>
                                <div class="flex gap-2"><button type="button" wire:click="$set('rejecting', null)" class="btn-secondary">{{ __('admin.cancel') }}</button><button class="btn-danger">{{ __('admin.reject') }}</button></div>
                                @error('rejectReason')<p class="field-error w-full">{{ $message }}</p>@enderror
                            </form>
                        @endif
                        @if ($expanded === $a->id)
                            <div class="mx-5 mb-4 overflow-hidden rounded-xl border border-sand-100 sm:mx-6 dark:border-ink-800">
                                <table class="table"><thead><tr><th>{{ __('catalog_admin.product') }}</th><th class="!text-end">{{ __('admin.quantity') }}</th><th class="!text-end">{{ __('inventory_admin.unit_cost') }}</th><th>{{ __('admin.notes') }}</th></tr></thead>
                                    <tbody>@foreach ($a->items as $it)
                                        <tr><td><x-admin.variant :variant="$it->variant" /></td>
                                            <td class="text-end font-semibold tabular-nums {{ $it->quantity > 0 ? 'text-emerald-soft' : 'text-ruby-soft' }}" dir="ltr">{{ $it->quantity > 0 ? '+' : '' }}{{ $it->quantity }}</td>
                                            <td class="text-end text-xs tabular-nums">{{ $it->unit_cost !== null ? \App\Support\Money::of((string) $it->unit_cost)->format() : '—' }}</td>
                                            <td class="text-xs text-ink-500">{{ $it->note ?: '—' }}</td></tr>
                                    @endforeach</tbody></table>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if ($adjustments->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $adjustments->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>

    <x-admin.dialog wire:model="showEditor" :title="__('inventory_admin.new_adjustment')" :description="__('inventory_admin.new_adjustment_hint')" max-width="max-w-3xl">
        <form wire:submit="save" class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-admin.field :label="__('admin.branch')" for="form.branch_id" required>
                    <select id="form.branch_id" wire:model="form.branch_id" class="input pe-10 rtl:[background-position:left_0.5rem_center]"><option value="">—</option>@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->code }} · {{ $b->localized('name') }}</option>@endforeach</select>
                </x-admin.field>
                <x-admin.field :label="__('inventory_admin.reason_code')" for="form.type" required>
                    <select id="form.type" wire:model.live="form.type" class="input pe-10 rtl:[background-position:left_0.5rem_center]">@foreach ($types as $t)<option value="{{ $t }}">{{ __('inventory_admin.type.'.$t) }}</option>@endforeach</select>
                </x-admin.field>
            </div>
            <p class="-mt-2 text-xs text-ink-500">{{ __('inventory_admin.type_hint.'.$form['type']) }}</p>
            <x-admin.field :label="__('admin.reason')" for="form.reason" required><input id="form.reason" wire:model="form.reason" class="input" placeholder="{{ __('inventory_admin.reason_ph') }}"></x-admin.field>

            <x-admin.variant-picker :results="$matches" :label="__('inventory_admin.add_products')" />
            @error('lines')<p class="field-error">{{ $message }}</p>@enderror
            @if ($lines)
                <div class="overflow-hidden rounded-xl border border-sand-200/70 dark:border-ink-700">
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>{{ __('catalog_admin.product') }}</th>@if ($form['type'] === 'adjustment')<th>{{ __('inventory_admin.direction') }}</th>@endif<th class="w-28">{{ __('admin.quantity') }}</th>
                            @if (in_array($form['type'], ['opening_balance', 'adjustment', 'customer_return']))<th class="w-32">{{ __('inventory_admin.unit_cost') }}</th>@endif<th></th></tr></thead>
                        <tbody>@foreach ($lines as $i => $l)
                            <tr wire:key="line-{{ $l['variant_id'] }}">
                                <td>@if ($lineVariants[$l['variant_id']] ?? null)<x-admin.variant :variant="$lineVariants[$l['variant_id']]" />@endif
                                    <input wire:model="lines.{{ $i }}.note" class="mt-1.5 w-full border-0 border-b border-dashed border-sand-200 bg-transparent px-0 py-0.5 text-xs text-ink-600 focus:border-brass-400 focus:ring-0 dark:border-ink-700" placeholder="{{ __('inventory_admin.line_note') }}" aria-label="{{ __('admin.notes') }}"></td>
                                @if ($form['type'] === 'adjustment')
                                    <td><select wire:model="lines.{{ $i }}.direction" class="input pe-10 rtl:[background-position:left_0.5rem_center] py-2 text-xs" aria-label="{{ __('inventory_admin.direction') }}"><option value="in">{{ __('inventory_admin.increase') }}</option><option value="out">{{ __('inventory_admin.decrease') }}</option></select></td>
                                @endif
                                <td><input type="number" min="1" wire:model="lines.{{ $i }}.quantity" class="input py-2 tabular-nums" aria-label="{{ __('admin.quantity') }}">@error("lines.$i.quantity")<p class="field-error">{{ $message }}</p>@enderror</td>
                                @if (in_array($form['type'], ['opening_balance', 'adjustment', 'customer_return']))
                                    <td><input wire:model="lines.{{ $i }}.unit_cost" inputmode="decimal" class="input py-2 tabular-nums" dir="ltr" placeholder="0.00" aria-label="{{ __('inventory_admin.unit_cost') }}"></td>
                                @endif
                                <td><button type="button" wire:click="removeLine({{ $i }})" class="btn-ghost btn-sm px-2 text-ruby-soft" aria-label="{{ __('org_admin.remove') }}"><x-icon name="x" class="size-4" /></button></td>
                            </tr>
                        @endforeach</tbody>
                    </table></div>
                </div>
            @endif
            <p class="flex items-start gap-2 rounded-xl bg-sand-50 p-3 text-xs text-ink-500 dark:bg-ink-800/60"><x-icon name="shield" class="mt-0.5 size-3.5 shrink-0" />{{ __('inventory_admin.segregation_hint') }}</p>
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary" @click="open = false">{{ __('admin.cancel') }}</button>
                <button class="btn-primary" wire:loading.attr="disabled" wire:target="save"><x-icon name="check" class="size-4" />{{ __('inventory_admin.submit_adjustment') }}</button>
            </div>
        </form>
    </x-admin.dialog>
</div>
