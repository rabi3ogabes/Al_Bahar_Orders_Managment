<div>
    <x-admin.page-header :title="__('nav.stock')" :eyebrow="__('nav.inventory')" :description="__('inventory_admin.stock_hint')">
        <x-slot:actions>
            @if ($this->can('inventory.create'))<a href="{{ route('admin.adjustments.index') }}" wire:navigate class="btn-secondary"><x-icon name="scale" class="size-4" />{{ __('nav.adjustments') }}</a>@endif
            @if ($this->can('transfers.create'))<a href="{{ route('admin.transfers.index') }}" wire:navigate class="btn-secondary"><x-icon name="transfer" class="size-4" />{{ __('nav.transfers') }}</a>@endif
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <x-admin.stat class="sm:col-span-2 xl:col-span-2" :label="__('inventory_admin.stock_value')" :value="$totalValue->format()" icon="wallet" tone="brass" :hint="__('inventory_admin.valuation_basis')" />
        <x-admin.stat :label="__('inventory_admin.units_on_hand')" :value="number_format($totals['units'])" icon="boxes" :hint="__('inventory_admin.reserved_n', ['n' => number_format($totals['reserved'])])" />
        <button type="button" wire:click="$set('filterLevel', 'low')" class="h-full text-start"><x-admin.stat :label="__('inventory_admin.low_stock')" :value="number_format($totals['low'])" icon="alert" class="h-full transition hover:border-brass-300" /></button>
        <button type="button" wire:click="$set('filterLevel', 'out')" class="h-full text-start"><x-admin.stat :label="__('inventory_admin.out_of_stock')" :value="number_format($totals['out'])" icon="ban" class="h-full transition hover:border-brass-300" /></button>
    </div>

    @if ($summaryBranches->count() > 1)
        <div class="card mb-6 overflow-hidden">
            <div class="p-5 sm:px-6"><h2 class="font-display text-xl font-semibold">{{ __('inventory_admin.valuation_by_branch') }}</h2></div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('admin.branch') }}</th><th class="!text-end">{{ __('inventory_admin.units_on_hand') }}</th><th class="!text-end">{{ __('inventory_admin.reserved') }}</th><th class="!text-end">{{ __('inventory_admin.low_stock') }}</th><th class="!text-end">{{ __('inventory_admin.out_of_stock') }}</th><th class="!text-end">{{ __('inventory_admin.stock_value') }}</th></tr></thead>
                <tbody>@foreach ($summaryBranches as $b) @php $s = $summary[$b->id]; @endphp
                    <tr wire:key="sum-{{ $b->id }}">
                        <td><button type="button" wire:click="$set('branch', '{{ $b->id }}')" class="text-start font-medium hover:text-brass-600">{{ $b->localized('name') }}</button> <span class="font-mono text-xs text-ink-400" dir="ltr">{{ $b->code }}</span></td>
                        <td class="text-end tabular-nums">{{ number_format($s->units) }}</td>
                        <td class="text-end tabular-nums text-ink-500">{{ number_format($s->reserved) }}</td>
                        <td class="text-end tabular-nums {{ $s->low_count ? 'text-amber-soft' : 'text-ink-400' }}">{{ $s->low_count }}</td>
                        <td class="text-end tabular-nums {{ $s->out_count ? 'text-ruby-soft' : 'text-ink-400' }}">{{ $s->out_count }}</td>
                        <td class="text-end font-medium tabular-nums">{{ $money($s->value)->format() }}</td>
                    </tr>
                @endforeach</tbody>
            </table></div>
        </div>
    @endif

    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('inventory_admin.stock_search')" />
            @if ($branches->count() > 1)
                <select wire:model.live="branch" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:w-48" aria-label="{{ __('admin.branch') }}">
                    <option value="">{{ __('admin.all_my_branches') }}</option>
                    @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->localized('name') }}</option>@endforeach
                </select>
            @endif
            <select wire:model.live="filterLevel" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:w-44" aria-label="{{ __('inventory_admin.stock_level') }}">
                <option value="">{{ __('inventory_admin.all_levels') }}</option>
                <option value="low">{{ __('inventory_admin.low_stock') }}</option>
                <option value="out">{{ __('inventory_admin.out_of_stock') }}</option>
                <option value="reserved">{{ __('inventory_admin.has_reservations') }}</option>
                <option value="over">{{ __('inventory_admin.over_max') }}</option>
            </select>
            <select wire:model.live="sort" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:w-44" aria-label="{{ __('catalog_admin.sort') }}">
                <option value="">{{ __('inventory_admin.sort_lowest') }}</option>
                <option value="available_desc">{{ __('inventory_admin.sort_highest') }}</option>
                <option value="recent">{{ __('inventory_admin.sort_recent') }}</option>
            </select>
            <x-slot:meta>{{ trans_choice('inventory_admin.lines_count', $rows->total(), ['count' => $rows->total()]) }}</x-slot:meta>
        </x-admin.toolbar>

        @if ($rows->isEmpty())
            <x-admin.empty :title="__('inventory_admin.stock_empty')" :description="__('inventory_admin.stock_empty_hint')" icon="boxes" />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr>
                        <th>{{ __('catalog_admin.product') }}</th><th>{{ __('admin.branch') }}</th><th class="!text-end">{{ __('inventory_admin.on_hand') }}</th>
                        <th class="!text-end">{{ __('inventory_admin.reserved') }}</th><th class="!text-end">{{ __('inventory_admin.available') }}</th>
                        <th class="!text-end">{{ __('inventory_admin.min_max') }}</th><th>{{ __('inventory_admin.bin') }}</th><th class="!text-end">{{ __('inventory_admin.avg_cost') }}</th><th class="w-20"></th>
                    </tr></thead>
                    <tbody>
                    @foreach ($rows as $r)
                        @php $avail = $r->available(); $state = $avail <= 0 ? 'out' : ($r->isLow() ? 'low' : 'ok'); @endphp
                        <tr wire:key="inv-{{ $r->id }}">
                            <td><x-admin.variant :variant="$r->variant" /></td>
                            <td><span class="badge-neutral font-mono" dir="ltr" title="{{ $r->branch->localized('name') }}">{{ $r->branch->code }}</span></td>
                            <td class="text-end tabular-nums">{{ number_format($r->on_hand) }}</td>
                            <td class="text-end tabular-nums text-ink-500">{{ $r->reserved ? number_format($r->reserved) : '—' }}</td>
                            <td class="text-end">
                                <span class="inline-flex items-center gap-1.5 font-semibold tabular-nums {{ $state === 'out' ? 'text-ruby-soft' : ($state === 'low' ? 'text-amber-soft' : 'text-ink-900 dark:text-ivory') }}">
                                    @if ($state !== 'ok')<span class="size-1.5 rounded-full bg-current"></span>@endif{{ number_format($avail) }}
                                </span>
                            </td>
                            <td class="text-end text-xs tabular-nums text-ink-500">{{ $r->minimum_stock }} / {{ $r->maximum_stock ?? '∞' }}</td>
                            <td class="font-mono text-xs text-ink-500" dir="ltr">{{ $r->bin_location ?: '—' }}</td>
                            <td class="whitespace-nowrap text-end text-xs tabular-nums text-ink-500">{{ $r->average_cost !== null ? \App\Support\Money::of((string) $r->average_cost)->format() : '—' }}</td>
                            <td>
                                <div class="flex justify-end gap-0.5">
                                    <a href="{{ route('admin.inventory.movements', ['search' => $r->variant->sku, 'branch' => $r->branch_id]) }}" wire:navigate class="btn-ghost btn-sm px-2" title="{{ __('nav.movements') }}" aria-label="{{ __('nav.movements') }}"><x-icon name="history" class="size-4" /></a>
                                    @if ($editable->contains($r->branch_id))<button type="button" wire:click="openThresholds('{{ $r->id }}')" class="btn-ghost btn-sm px-2" title="{{ __('inventory_admin.edit_thresholds') }}" aria-label="{{ __('inventory_admin.edit_thresholds') }}"><x-icon name="settings" class="size-4" /></button>@endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($rows->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $rows->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>

    <x-admin.dialog wire:model="showEditor" :title="__('inventory_admin.edit_thresholds')" :description="__('inventory_admin.thresholds_hint')">
        <form wire:submit="saveThresholds" class="space-y-5">
            <div class="grid grid-cols-2 gap-4">
                <x-admin.field :label="__('inventory_admin.min_stock')" for="edit.minimum_stock" required :hint="__('inventory_admin.min_hint')"><input id="edit.minimum_stock" type="number" min="0" wire:model="edit.minimum_stock" class="input tabular-nums"></x-admin.field>
                <x-admin.field :label="__('inventory_admin.max_stock')" for="edit.maximum_stock" :hint="__('inventory_admin.max_hint')"><input id="edit.maximum_stock" type="number" min="0" wire:model="edit.maximum_stock" class="input tabular-nums"></x-admin.field>
            </div>
            <x-admin.field :label="__('inventory_admin.bin')" for="edit.bin_location" :hint="__('inventory_admin.bin_hint')"><input id="edit.bin_location" wire:model="edit.bin_location" class="input font-mono" dir="ltr" placeholder="A-03-2"></x-admin.field>
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary" @click="open = false">{{ __('admin.cancel') }}</button>
                <button class="btn-primary" wire:loading.attr="disabled" wire:target="saveThresholds"><x-icon name="check" class="size-4" />{{ __('admin.save') }}</button>
            </div>
        </form>
    </x-admin.dialog>
</div>
