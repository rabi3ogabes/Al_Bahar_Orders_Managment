<div>
    <x-admin.page-header :title="__('nav.branches')" :eyebrow="__('nav.organization')" :description="__('branches.index_hint')">
        <x-slot:actions>
            @if ($canCreate)
                <a href="{{ route('admin.branches.create') }}" wire:navigate class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('branches.new') }}</a>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <div class="card">
        <div class="flex flex-col gap-3 border-b border-sand-100 p-4 sm:flex-row sm:items-center dark:border-ink-800">
            <x-admin.search :placeholder="__('branches.search')" />
            <select wire:model.live="filterStatus" class="input sm:w-44" aria-label="{{ __('admin.status') }}">
                <option value="">{{ __('admin.all_statuses') }}</option>
                <option value="active">{{ __('status.active') }}</option>
                <option value="inactive">{{ __('status.inactive') }}</option>
            </select>
            <div class="ms-auto text-sm text-ink-500" wire:loading.remove>{{ trans_choice('branches.count', $branches->total(), ['count' => $branches->total()]) }}</div>
            <div class="ms-auto" wire:loading><x-icon name="refresh" class="size-4 animate-spin text-brass-500" /></div>
        </div>

        @if ($branches->isEmpty())
            <x-admin.empty :title="__('branches.empty_title')" :description="__('branches.empty_hint')" icon="store">
                @if ($canCreate)<a href="{{ route('admin.branches.create') }}" wire:navigate class="btn-brass"><x-icon name="plus" class="size-4" />{{ __('branches.new') }}</a>@endif
            </x-admin.empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr>
                        <th>{{ __('branches.branch') }}</th><th>{{ __('branches.area') }}</th><th>{{ __('branches.now') }}</th>
                        <th>{{ __('branches.services') }}</th><th class="!text-end">{{ __('nav.employees') }}</th><th>{{ __('admin.status') }}</th><th class="w-12"></th>
                    </tr></thead>
                    <tbody>
                    @foreach ($branches as $b)
                        @php $st = $hours->status($b); @endphp
                        <tr wire:key="b-{{ $b->id }}">
                            <td>
                                <a href="{{ route('admin.branches.edit', $b) }}" wire:navigate class="group flex items-center gap-3">
                                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-sand-100 font-mono text-xs font-semibold text-ink-700 dark:bg-ink-800 dark:text-sand-200">{{ $b->code }}</span>
                                    <span class="min-w-0"><span class="block truncate font-medium text-ink-900 group-hover:text-brass-600 dark:text-ivory">{{ $b->localized('name') }}</span>
                                        <span class="block truncate text-xs text-ink-500" dir="ltr">{{ $b->phone }}</span></span>
                                </a>
                            </td>
                            <td class="text-ink-600">{{ $b->area ?: '—' }}</td>
                            <td>@if ($st['is_open'])<span class="badge-success"><span class="size-1.5 rounded-full bg-current"></span>{{ __('branches.open_now') }}</span>@else<span class="badge-neutral">{{ __('branches.closed_reason.'.$st['reason']) }}</span>@endif</td>
                            <td><div class="flex flex-wrap gap-1.5">
                                @if ($b->pickup_enabled)<span class="badge-brass"><x-icon name="qr" class="size-3" />{{ __('branches.pickup') }}</span>@endif
                                @if ($b->delivery_enabled)<span class="badge-info"><x-icon name="truck" class="size-3" />{{ __('branches.delivery') }}</span>@endif
                                @if ($b->cod_enabled)<span class="badge-neutral">COD</span>@endif
                            </div></td>
                            <td class="text-end tabular-nums">{{ $b->employee_assignments_count }}</td>
                            <td><x-admin.status :value="$b->status" /></td>
                            <td><a href="{{ route('admin.branches.edit', $b) }}" wire:navigate class="btn-ghost btn-sm" aria-label="{{ __('admin.edit') }}"><x-icon name="chevron-right" class="size-4 rtl-flip" /></a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $branches->links('components.admin.pagination') }}</div>
        @endif
    </div>
</div>
