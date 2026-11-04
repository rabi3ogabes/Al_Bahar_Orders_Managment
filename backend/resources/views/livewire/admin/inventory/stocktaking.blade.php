<div>
    <x-admin.page-header :title="__('nav.stocktaking')" :eyebrow="__('nav.inventory')" :description="__('inventory_admin.stocktaking_hint')">
        <x-slot:actions>
            @if ($branches->isNotEmpty())<button type="button" wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('inventory_admin.start_count') }}</button>@endif
        </x-slot:actions>
    </x-admin.page-header>
    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('inventory_admin.number_search')" />
            <select wire:model.live="filterStatus" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:w-44" aria-label="{{ __('admin.status') }}">
                <option value="">{{ __('admin.all_statuses') }}</option>
                @foreach (['counting', 'submitted', 'posted', 'cancelled'] as $st)<option value="{{ $st }}">{{ __('status.'.$st) }}</option>@endforeach
            </select>
        </x-admin.toolbar>
        @if ($sessions->isEmpty())
            <x-admin.empty :title="__('inventory_admin.no_sessions')" :description="__('inventory_admin.no_sessions_hint')" icon="clipboard" />
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('inventory_admin.session') }}</th><th>{{ __('admin.branch') }}</th><th>{{ __('inventory_admin.progress') }}</th><th>{{ __('inventory_admin.started_by') }}</th><th>{{ __('admin.status') }}</th><th class="w-12"></th></tr></thead>
                <tbody>@foreach ($sessions as $ses)
                    @php $pct = $ses->items_count ? round($ses->counted_count * 100 / $ses->items_count) : 0; @endphp
                    <tr wire:key="ss-{{ $ses->id }}">
                        <td><a href="{{ route('admin.stocktaking.show', $ses) }}" wire:navigate class="font-mono font-medium hover:text-brass-600" dir="ltr">{{ $ses->number }}</a><span class="block text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($ses->created_at) }}</span></td>
                        <td>{{ $ses->branch->localized('name') }}</td>
                        <td><div class="flex min-w-[10rem] items-center gap-3"><div class="h-1.5 w-24 overflow-hidden rounded-full bg-sand-100 dark:bg-ink-800"><div class="h-full rounded-full bg-brass-500" style="width: {{ $pct }}%"></div></div><span class="text-xs text-ink-500 tabular-nums">{{ $ses->counted_count }} / {{ $ses->items_count }}</span></div></td>
                        <td class="text-sm text-ink-600">{{ $people[$ses->started_by] ?? '—' }}</td>
                        <td><x-admin.status :value="$ses->status" /></td>
                        <td><a href="{{ route('admin.stocktaking.show', $ses) }}" wire:navigate class="btn-ghost btn-sm" aria-label="{{ __('admin.details') }}"><x-icon name="chevron-right" class="size-4 rtl-flip" /></a></td>
                    </tr>
                @endforeach</tbody>
            </table></div>
            @if ($sessions->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $sessions->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>

    <x-admin.dialog wire:model="showEditor" :title="__('inventory_admin.start_count')" :description="__('inventory_admin.start_count_hint')">
        <form wire:submit="start" class="space-y-5">
            <x-admin.field :label="__('admin.branch')" for="form.branch_id" required>
                <select id="form.branch_id" wire:model="form.branch_id" class="input pe-10 rtl:[background-position:left_0.5rem_center]"><option value="">—</option>@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->code }} · {{ $b->localized('name') }}</option>@endforeach</select>
            </x-admin.field>
            <x-admin.field :label="__('inventory_admin.limit_to_category')" for="form.category_id" :hint="__('inventory_admin.limit_to_category_hint')">
                <select id="form.category_id" wire:model="form.category_id" class="input pe-10 rtl:[background-position:left_0.5rem_center]"><option value="">{{ __('inventory_admin.whole_branch') }}</option>@foreach ($categories as $c)<option value="{{ $c->id }}">{{ str_repeat('— ', $c->depth) }}{{ $c->trans('name') }}</option>@endforeach</select>
            </x-admin.field>
            <x-admin.field :label="__('admin.notes')" for="form.notes"><textarea id="form.notes" wire:model="form.notes" rows="2" class="input"></textarea></x-admin.field>
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary" @click="open = false">{{ __('admin.cancel') }}</button>
                <button class="btn-primary" wire:loading.attr="disabled" wire:target="start"><x-icon name="play" class="size-4" />{{ __('inventory_admin.start_count') }}</button>
            </div>
        </form>
    </x-admin.dialog>
</div>
