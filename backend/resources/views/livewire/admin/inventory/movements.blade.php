<div>
    <x-admin.page-header :title="__('nav.movements')" :eyebrow="__('nav.inventory')" :description="__('inventory_admin.movements_hint')" />
    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('inventory_admin.movements_search')" />
            @if ($branches->count() > 1)
                <select wire:model.live="branch" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:w-44" aria-label="{{ __('admin.branch') }}">
                    <option value="">{{ __('admin.all_my_branches') }}</option>
                    @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->localized('name') }}</option>@endforeach
                </select>
            @endif
            <select wire:model.live="filterType" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:w-48" aria-label="{{ __('inventory_admin.movement_type') }}">
                <option value="">{{ __('inventory_admin.all_types') }}</option>
                @foreach ($types as $t)<option value="{{ $t }}">{{ __('inventory_admin.mv.'.$t) }}</option>@endforeach
            </select>
            <div class="flex items-center gap-2">
                <input type="date" wire:model.live="from" class="input sm:w-40" aria-label="{{ __('org_admin.from') }}"><span class="text-ink-400">–</span><input type="date" wire:model.live="to" class="input sm:w-40" aria-label="{{ __('org_admin.to') }}">
            </div>
        </x-admin.toolbar>
        @if ($movements->isEmpty())
            <x-admin.empty :title="__('inventory_admin.movements_empty')" :description="__('inventory_admin.movements_empty_hint')" icon="history" />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr>
                        <th>{{ __('admin.date') }}</th><th>{{ __('catalog_admin.product') }}</th><th>{{ __('admin.branch') }}</th><th>{{ __('inventory_admin.movement_type') }}</th>
                        <th class="!text-end">{{ __('inventory_admin.change') }}</th><th class="!text-end">{{ __('inventory_admin.balance_after') }}</th><th>{{ __('inventory_admin.reference') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($movements as $m)
                        <tr wire:key="mv-{{ $m->id }}">
                            <td class="whitespace-nowrap text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($m->created_at) }}<span class="block">{{ $m->actor?->name ?? __('org_admin.system') }}</span></td>
                            <td><x-admin.variant :variant="$m->variant" /></td>
                            <td><span class="badge-neutral font-mono" dir="ltr">{{ $m->branch->code }}</span></td>
                            <td><span class="{{ $m->quantity > 0 ? 'badge-success' : ($m->quantity < 0 ? 'badge-danger' : 'badge-info') }}">{{ __('inventory_admin.mv.'.$m->type) }}</span></td>
                            <td class="whitespace-nowrap text-end tabular-nums" dir="ltr">
                                @if ($m->quantity)<span class="font-semibold {{ $m->quantity > 0 ? 'text-emerald-soft' : 'text-ruby-soft' }}">{{ $m->quantity > 0 ? '+' : '' }}{{ $m->quantity }}</span>@endif
                                @if ($m->reserved_delta)<span class="block text-xs text-sky-soft dark:text-sky-300">{{ __('inventory_admin.reserved_short') }} {{ $m->reserved_delta > 0 ? '+' : '' }}{{ $m->reserved_delta }}</span>@endif
                            </td>
                            <td class="whitespace-nowrap text-end text-xs tabular-nums text-ink-500">{{ $m->on_hand_after }} <span class="text-ink-400">/ {{ $m->reserved_after }}</span></td>
                            <td class="max-w-[16rem] text-xs text-ink-500">
                                @if ($m->reference_type)<span class="block font-mono" dir="ltr">{{ $m->reference_type }}</span>@endif
                                @if ($m->reason)<span class="block truncate" title="{{ $m->reason }}">{{ $m->reason }}</span>@endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($movements->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $movements->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>
</div>
