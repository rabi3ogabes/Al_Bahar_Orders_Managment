<div>
    <x-admin.page-header :title="__('nav.employees')" :eyebrow="__('nav.organization')" :description="__('org_admin.employees_hint')">
        <x-slot:actions>
            @if ($canCreate)
                <a href="{{ route('admin.employees.create') }}" wire:navigate class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('org_admin.new_employee') }}</a>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('org_admin.employees_search')" />
            @if ($branches->count() > 1)
                <select wire:model.live="branch" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:w-48" aria-label="{{ __('admin.branch') }}">
                    <option value="">{{ __('admin.all_my_branches') }}</option>
                    @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->localized('name') }}</option>@endforeach
                </select>
            @endif
            <select wire:model.live="filterRole" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:w-48" aria-label="{{ __('admin.roles') }}">
                <option value="">{{ __('org_admin.all_roles') }}</option>
                @foreach ($roles as $r)<option value="{{ $r->key }}">{{ $r->localized('name') }}</option>@endforeach
            </select>
            <select wire:model.live="filterStatus" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:w-40" aria-label="{{ __('admin.status') }}">
                <option value="">{{ __('admin.all_statuses') }}</option>
                @foreach (['active', 'suspended', 'deactivated'] as $st)<option value="{{ $st }}">{{ __('status.'.$st) }}</option>@endforeach
            </select>
            <x-slot:meta>{{ trans_choice('org_admin.employees_count', $employees->total(), ['count' => $employees->total()]) }}</x-slot:meta>
        </x-admin.toolbar>

        @if ($employees->isEmpty())
            <x-admin.empty :title="__('org_admin.employees_empty')" :description="__('org_admin.employees_empty_hint')" icon="users" />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr>
                        <th>{{ __('org_admin.employee') }}</th><th>{{ __('admin.branches') }}</th><th>{{ __('admin.company_wide') }}</th>
                        <th>{{ __('admin.last_login') }}</th><th>{{ __('admin.status') }}</th><th class="w-12"></th>
                    </tr></thead>
                    <tbody>
                    @foreach ($employees as $e)
                        @php
                            $globalRoles = $e->roleAssignments->whereNull('branch_id');
                            $rolesByBranch = $e->roleAssignments->whereNotNull('branch_id')->groupBy('branch_id');
                        @endphp
                        <tr wire:key="e-{{ $e->id }}">
                            <td>
                                <a href="{{ route('admin.employees.edit', $e) }}" wire:navigate class="group flex items-center gap-3">
                                    <span class="grid size-10 shrink-0 place-items-center rounded-full bg-sand-100 text-sm font-semibold text-ink-700 dark:bg-ink-800 dark:text-sand-200">{{ mb_substr($e->name, 0, 1) }}</span>
                                    <span class="min-w-0">
                                        <span class="flex items-center gap-2 truncate font-medium text-ink-900 group-hover:text-brass-600 dark:text-ivory">{{ $e->name }}
                                            @if ($e->is_super_admin)<span class="badge-brass"><x-icon name="crown" class="size-3" />{{ __('admin.super_admin') }}</span>@endif
                                            @if ($e->is(auth()->user()))<span class="badge-neutral">{{ __('org_admin.you') }}</span>@endif
                                        </span>
                                        <span class="block truncate text-xs text-ink-500" dir="ltr">{{ $e->email }}</span>
                                        @if ($e->job_title)<span class="block truncate text-xs text-ink-400">{{ $e->job_title }}</span>@endif
                                    </span>
                                </a>
                            </td>
                            <td>
                                <div class="flex max-w-sm flex-wrap gap-1.5">
                                    @forelse ($e->branchAssignments as $a)
                                        @php $rk = ($rolesByBranch[$a->branch_id] ?? collect())->map(fn ($ur) => $ur->role->localized('name'))->implode('، '); @endphp
                                        <span class="{{ $a->is_active ? 'badge-neutral' : 'badge-neutral opacity-50 line-through' }}" title="{{ $a->branch->localized('name') }}{{ $rk ? ' — '.$rk : '' }}">
                                            <span class="font-mono" dir="ltr">{{ $a->branch->code }}</span>@if ($rk)<span class="max-w-[9rem] truncate text-ink-500">{{ $rk }}</span>@endif
                                        </span>
                                    @empty
                                        <span class="text-ink-400">—</span>
                                    @endforelse
                                </div>
                            </td>
                            <td>
                                <div class="flex flex-wrap gap-1.5">
                                    @forelse ($globalRoles as $ur)<span class="badge-brass">{{ $ur->role->localized('name') }}</span>@empty<span class="text-ink-400">—</span>@endforelse
                                </div>
                            </td>
                            <td class="whitespace-nowrap text-xs text-ink-500 tabular-nums">{{ $e->last_login_at ? \App\Support\BusinessTime::display($e->last_login_at) : __('org_admin.never') }}</td>
                            <td><x-admin.status :value="$e->status->value" /></td>
                            <td><a href="{{ route('admin.employees.edit', $e) }}" wire:navigate class="btn-ghost btn-sm" aria-label="{{ __('admin.edit') }}"><x-icon name="chevron-right" class="size-4 rtl-flip" /></a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($employees->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $employees->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>
</div>
