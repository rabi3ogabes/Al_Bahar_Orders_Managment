<div>
    <x-admin.page-header :title="__('nav.employees')" :eyebrow="__('nav.organization')" :description="__('org_admin.employees_hint')">
        <x-slot:actions>
            @if ($canCreate)
                <x-admin.open-editor create :id="''" :href="route('admin.employees.create')" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('branch_access.add_employee') }}</x-admin.open-editor>
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
                <option value="{{ \App\Livewire\Admin\Organization\EmployeeIndex::FILTER_PERSONAL }}">{{ __('branch_access.filter_personal') }}</option>
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
                        <th>{{ __('org_admin.employee') }}</th><th>{{ __('admin.branches') }}</th><th class="hidden md:table-cell">{{ __('branch_access.role_or_preset') }}</th>
                        <th class="hidden md:table-cell">{{ __('branch_access.features') }}</th><th class="hidden xl:table-cell">{{ __('admin.last_login') }}</th>
                        <th>{{ __('admin.status') }}</th><th class="w-12"></th>
                    </tr></thead>
                    <tbody>
                    @foreach ($employees as $e)
                        @php
                            $globalRoles = $e->roleAssignments->whereNull('branch_id');
                            $branchRoles = $e->roleAssignments->whereNotNull('branch_id');
                            $rolesByBranch = $branchRoles->groupBy('branch_id');
                            $mine = $features[$e->id] ?? [];
                            $presetId = $presets[$e->id] ?? null;
                            $hasPersonal = $branchRoles->contains(fn ($ur) => $ur->role->isPersonal());
                            $sharedNames = $branchRoles->reject(fn ($ur) => $ur->role->isPersonal())->map(fn ($ur) => $ur->role->localized('name'))->unique()->values();
                            $isYou = $e->is(auth()->user());
                            $active = $e->status->value === 'active';
                            $featureNames = collect($mine)->map(fn ($f) => \App\Domain\Identity\Support\BranchFeatureCatalog::label($f))->implode(' · ');
                        @endphp
                        <tr wire:key="e-{{ $e->id }}">
                            <td>
                                <x-admin.open-editor :id="$e->id" :href="route('admin.employees.edit', $e)" class="group flex min-h-11 items-center gap-3">
                                    <span class="grid size-10 shrink-0 place-items-center rounded-full bg-sand-100 text-sm font-semibold text-ink-700 dark:bg-ink-800 dark:text-sand-200">{{ mb_substr($e->name, 0, 1) }}</span>
                                    <span class="min-w-0">
                                        <span class="flex items-center gap-2 truncate font-medium text-ink-900 group-hover:text-brass-600 dark:text-ivory">{{ $e->name }}
                                            @if ($e->is_super_admin)<span class="badge-brass"><x-icon name="crown" class="size-3" />{{ __('admin.super_admin') }}</span>@endif
                                            @if ($isYou)<span class="badge-neutral">{{ __('org_admin.you') }}</span>@endif
                                        </span>
                                        <span class="block truncate text-xs text-ink-500" dir="ltr">{{ $e->email ?: $e->phone }}</span>
                                        @if ($e->job_title)<span class="block truncate text-xs text-ink-400">{{ $e->job_title }}</span>@endif
                                        @if ($e->must_change_password)<span class="mt-0.5 block text-xs text-amber-soft">{{ __('branch_access.waiting_first_sign_in') }}</span>@endif
                                    </span>
                                </x-admin.open-editor>
                            </td>
                            <td>
                                <div class="flex max-w-sm flex-wrap gap-1.5">
                                    @forelse ($e->branchAssignments as $a)
                                        <span class="{{ $a->is_active ? 'badge-neutral' : 'badge-neutral opacity-50 line-through' }}" title="{{ $a->branch->localized('name') }}">
                                            <span class="font-mono" dir="ltr">{{ $a->branch->code }}</span>
                                        </span>
                                    @empty
                                        <span class="text-ink-400">—</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="hidden md:table-cell">
                                <div class="flex max-w-xs flex-wrap gap-1.5">
                                    @foreach ($globalRoles as $ur)<span class="badge-brass">{{ $ur->role->localized('name') }}</span>@endforeach
                                    @if ($hasPersonal)
                                        <span class="badge-info">{{ $presetId ? __('branch_access.preset_'.$presetId) : __('branch_access.custom_access') }}</span>
                                    @endif
                                    @foreach ($sharedNames as $n)<span class="badge-neutral">{{ $n }}</span>@endforeach
                                    @if ($globalRoles->isEmpty() && ! $hasPersonal && $sharedNames->isEmpty())<span class="text-ink-400">—</span>@endif
                                </div>
                            </td>
                            <td class="hidden md:table-cell">
                                @if (count($mine))
                                    <span x-data="{ tip: false }" class="relative inline-block" @mouseenter="tip = true" @mouseleave="tip = false" @keydown.escape.window="tip = false">
                                        <span tabindex="0" role="button" class="badge-neutral min-h-8 cursor-help tabular-nums" aria-describedby="tip-{{ $e->id }}" @focus="tip = true" @blur="tip = false">
                                            <x-icon name="shield" class="size-3" />{{ $e->isSuperAdmin() ? __('branch_access.all_features') : __('branch_access.features_count', ['n' => count($mine), 'total' => $totalFeatures]) }}
                                        </span>
                                        <span x-show="tip" x-cloak x-transition.opacity.duration.120ms role="tooltip" id="tip-{{ $e->id }}"
                                              class="pointer-events-none absolute start-0 top-full z-30 mt-1.5 w-64 rounded-lg bg-ink-900 px-3 py-2 text-xs leading-relaxed text-ivory shadow-lg dark:bg-sand-100 dark:text-ink-900">{{ $featureNames }}</span>
                                    </span>
                                @else
                                    <span class="text-ink-400">—</span>
                                @endif
                            </td>
                            <td class="hidden whitespace-nowrap text-xs text-ink-500 tabular-nums xl:table-cell">{{ $e->last_login_at ? \App\Support\BusinessTime::display($e->last_login_at) : __('org_admin.never') }}</td>
                            <td>
                                <div class="flex items-center gap-2">
                                    @if (($manageable[$e->id] ?? false) && in_array($e->status->value, ['active', 'suspended'], true))
                                        <x-admin.switch :on="$active" :label="($active ? __('branch_access.disable_named', ['name' => $e->name]) : __('branch_access.enable_named', ['name' => $e->name]))"
                                                        wire:click="toggleActive('{{ $e->id }}')" wire:loading.attr="disabled" wire:target="toggleActive('{{ $e->id }}')" />
                                    @endif
                                    <x-admin.status :value="$e->status->value" class="hidden sm:inline-flex" />
                                </div>
                            </td>
                            <td><x-admin.open-editor :id="$e->id" :href="route('admin.employees.edit', $e)" class="btn-ghost btn-sm min-h-11 min-w-11" aria-label="{{ __('admin.edit') }}"><x-icon name="chevron-right" class="size-4 rtl-flip" /></x-admin.open-editor></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($employees->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $employees->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>

    {{-- Edit (or add) an employee beside the list; the same editor opens as a full page from the window's "Open full page". --}}
    <x-admin.record-editor editor="admin.organization.employee-editor" param="employee" max-width="max-w-4xl"
        :editing="$editing" :record="$editEmployee" :landing="$landing" :creating="$creating"
        :title="$editEmployee?->name" :description="$editEmployee ? ($editEmployee->email ?: $editEmployee->phone).($editEmployee->job_title ? ' · '.$editEmployee->job_title : '') : null"
        :full-url="$editEmployee ? route('admin.employees.edit', $editEmployee) : null"
        :new-title="__('branch_access.add_employee')" :new-description="__('branch_access.add_employee_hint')" :new-url="$canCreate ? route('admin.employees.create') : null">
        @if ($editEmployee)
            <x-slot:badges>
                @if ($editEmployee->is_super_admin)<span class="badge-brass"><x-icon name="crown" class="size-3" />{{ __('admin.super_admin') }}</span>@endif
                @if ($editEmployee->hasTwoFactorEnabled())<span class="badge-success"><x-icon name="lock" class="size-3" />2FA</span>@endif
                <x-admin.status :value="$editEmployee->status->value" />
            </x-slot:badges>
        @endif
    </x-admin.record-editor>
</div>
