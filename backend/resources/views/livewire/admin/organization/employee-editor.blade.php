@php $isNew = ! $employee; @endphp
<div class="mx-auto max-w-6xl">
    <a href="{{ route('admin.employees.index') }}" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-ink-500 hover:text-brass-600"><x-icon name="chevron-left" class="size-4 rtl-flip" />{{ __('nav.employees') }}</a>

    @if ($isNew)
        <x-admin.page-header :title="__('org_admin.new_employee')" :eyebrow="__('nav.organization')" :description="__('org_admin.new_employee_hint')" />
        <form wire:submit="create" class="grid gap-6 [&>*]:min-w-0 lg:grid-cols-3">
            <div class="min-w-0 card card-pad space-y-5 lg:col-span-2">
                <h2 class="font-display text-xl font-semibold">{{ __('admin.personal_details') }}</h2>
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-admin.field :label="__('admin.name')" for="form.name" required class="sm:col-span-2"><input id="form.name" wire:model="form.name" class="input" autocomplete="off"></x-admin.field>
                    <x-admin.field :label="__('admin.email')" for="form.email" required><input id="form.email" type="email" wire:model="form.email" class="input" dir="ltr" autocomplete="off"></x-admin.field>
                    <x-admin.field :label="__('org_admin.phone')" for="form.phone"><input id="form.phone" wire:model="form.phone" class="input" dir="ltr" placeholder="+974 5555 0000"></x-admin.field>
                    <x-admin.field :label="__('org_admin.job_title')" for="form.job_title"><input id="form.job_title" wire:model="form.job_title" class="input"></x-admin.field>
                    <x-admin.field :label="__('admin.language')" for="form.locale">
                        <select id="form.locale" wire:model="form.locale" class="input pe-10 rtl:[background-position:left_0.5rem_center]"><option value="en">English</option><option value="ar">العربية</option></select>
                    </x-admin.field>
                </div>
            </div>
            <div class="space-y-6">
                <div class="card card-pad space-y-4">
                    <h2 class="font-display text-xl font-semibold">{{ __('org_admin.initial_password') }}</h2>
                    <x-admin.field :label="__('admin.password')" for="form.password" required :hint="__('admin.password_policy')">
                        <div class="flex gap-2">
                            <input id="form.password" type="{{ $showPassword ? 'text' : 'password' }}" wire:model="form.password" class="input font-mono" dir="ltr" autocomplete="new-password">
                            <button type="button" wire:click="generatePassword" class="btn-secondary shrink-0 px-3" title="{{ __('org_admin.generate') }}" aria-label="{{ __('org_admin.generate') }}"><x-icon name="sparkles" class="size-4" /></button>
                        </div>
                    </x-admin.field>
                    <p class="rounded-xl bg-sand-50 p-3 text-xs leading-relaxed text-ink-500 dark:bg-ink-800 dark:text-ink-400">{{ __('org_admin.initial_password_hint') }}</p>
                </div>
                <button class="btn-primary w-full py-3" wire:loading.attr="disabled" wire:target="create"><x-icon name="check" class="size-4" />{{ __('org_admin.create_employee') }}</button>
            </div>
        </form>
    @else
        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex min-w-0 items-center gap-4">
                <span class="grid size-14 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-sand-100 to-sand-200 font-display text-2xl font-semibold text-ink-700 dark:from-ink-800 dark:to-ink-700 dark:text-sand-100">{{ mb_substr($employee->name, 0, 1) }}</span>
                <div class="min-w-0">
                    <p class="eyebrow mb-1">{{ $employee->job_title ?: __('admin.staff') }}</p>
                    <h1 class="truncate font-display text-3xl font-semibold tracking-tight text-ink-900 dark:text-ivory">{{ $employee->name }}</h1>
                    <p class="truncate text-sm text-ink-500" dir="ltr">{{ $employee->email }}</p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if ($employee->is_super_admin)<span class="badge-brass"><x-icon name="crown" class="size-3" />{{ __('admin.super_admin') }}</span>@endif
                @if ($employee->hasTwoFactorEnabled())<span class="badge-success"><x-icon name="lock" class="size-3" />2FA</span>@endif
                <x-admin.status :value="$employee->status->value" />
            </div>
        </div>

        @if ($isSelf)
            <div class="mb-6 flex items-start gap-3 rounded-2xl border border-amber-soft/20 bg-amber-soft/5 p-4 text-sm text-ink-700 dark:text-sand-200">
                <x-icon name="info" class="mt-0.5 size-4 shrink-0 text-amber-soft" />
                <p>{{ __('org_admin.self_edit_notice') }} <a href="{{ route('admin.profile') }}" wire:navigate class="font-medium text-brass-600 underline-offset-2 hover:underline">{{ __('admin.profile') }}</a></p>
            </div>
        @endif

        <x-admin.tabs :current="$tab" :tabs="['profile' => ['user', __('org_admin.tab_profile')], 'access' => ['shield', __('org_admin.tab_access')], 'security' => ['lock', __('org_admin.tab_security')], 'activity' => ['history', __('org_admin.tab_activity')]]" />

        @if ($tab === 'profile')
            <form wire:submit="saveProfile" class="card card-pad max-w-3xl space-y-5">
                <h2 class="font-display text-xl font-semibold">{{ __('admin.personal_details') }}</h2>
                <fieldset class="grid gap-5 sm:grid-cols-2" @disabled(! $canManage)>
                    <x-admin.field :label="__('admin.name')" for="form.name" required class="sm:col-span-2"><input id="form.name" wire:model="form.name" class="input"></x-admin.field>
                    <x-admin.field :label="__('admin.email')" for="form.email" required><input id="form.email" type="email" wire:model="form.email" class="input" dir="ltr"></x-admin.field>
                    <x-admin.field :label="__('org_admin.phone')" for="form.phone"><input id="form.phone" wire:model="form.phone" class="input" dir="ltr"></x-admin.field>
                    <x-admin.field :label="__('org_admin.job_title')" for="form.job_title"><input id="form.job_title" wire:model="form.job_title" class="input"></x-admin.field>
                    <x-admin.field :label="__('admin.language')" for="form.locale">
                        <select id="form.locale" wire:model="form.locale" class="input pe-10 rtl:[background-position:left_0.5rem_center]"><option value="en">English</option><option value="ar">العربية</option></select>
                    </x-admin.field>
                </fieldset>
                @if ($canManage)
                    <div class="flex justify-end border-t border-sand-100 pt-5 dark:border-ink-800"><button class="btn-primary" wire:loading.attr="disabled" wire:target="saveProfile"><x-icon name="check" class="size-4" />{{ __('admin.save_changes') }}</button></div>
                @elseif (! $isSelf)
                    <p class="text-xs text-ink-500">{{ __('org_admin.read_only_profile') }}</p>
                @endif
            </form>

        @elseif ($tab === 'access')
            <div class="grid gap-6 [&>*]:min-w-0 lg:grid-cols-3">
                <div class="min-w-0 space-y-6 lg:col-span-2">
                    <div class="card overflow-hidden">
                        <div class="p-5 sm:p-6"><h2 class="font-display text-xl font-semibold">{{ __('org_admin.branch_assignments') }}</h2><p class="mt-1 text-sm text-ink-500">{{ __('org_admin.branch_assignments_hint') }}</p></div>
                        @if ($employee->branchAssignments->isEmpty())
                            <x-admin.empty :title="__('org_admin.no_assignments')" icon="store" class="!py-10" />
                        @else
                            <ul class="divide-y divide-sand-100 border-t border-sand-100 dark:divide-ink-800 dark:border-ink-800">
                                @foreach ($employee->branchAssignments as $a)
                                    @php $canAssignHere = ! $isSelf && $assignableBranches->contains('id', $a->branch_id); @endphp
                                    <li wire:key="a-{{ $a->id }}" class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:px-6 {{ $a->is_active ? '' : 'opacity-60' }}">
                                        <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-sand-100 font-mono text-xs font-semibold text-ink-700 dark:bg-ink-800 dark:text-sand-200">{{ $a->branch->code }}</span>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-2 font-medium">{{ $a->branch->localized('name') }}
                                                @if (! $a->is_active)<span class="badge-neutral">{{ __('status.inactive') }}</span>@endif
                                                @if ($a->is_delivery_driver)<span class="badge-info"><x-icon name="truck" class="size-3" />{{ __('org_admin.driver') }}</span>@endif
                                            </div>
                                            <div class="mt-1 flex flex-wrap gap-1.5">
                                                @forelse ($rolesByBranch[$a->branch_id] ?? [] as $ur)<span class="badge-brass">{{ $ur->role->localized('name') }}</span>@empty<span class="text-xs text-ink-400">{{ __('org_admin.no_roles') }}</span>@endforelse
                                                @if ($a->job_title)<span class="text-xs text-ink-500">· {{ $a->job_title }}</span>@endif
                                            </div>
                                        </div>
                                        @if ($canAssignHere)
                                            <div class="flex shrink-0 gap-1">
                                                <button type="button" wire:click="editAssignment('{{ $a->branch_id }}')" class="btn-ghost btn-sm"><x-icon name="edit" class="size-4" />{{ __('admin.edit') }}</button>
                                                <button type="button" wire:click="toggleAssignment('{{ $a->branch_id }}')" class="btn-ghost btn-sm">{{ $a->is_active ? __('admin.disable') : __('admin.enable') }}</button>
                                                <button type="button" wire:click="removeAssignment('{{ $a->branch_id }}')" wire:confirm="{{ __('org_admin.confirm_remove_assignment') }}" class="btn-ghost btn-sm text-ruby-soft" aria-label="{{ __('org_admin.remove') }}"><x-icon name="trash" class="size-4" /></button>
                                            </div>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    @if ($canAssignGlobal || $employee->roleAssignments->whereNull('branch_id')->isNotEmpty())
                        <form wire:submit="saveGlobalRoles" class="card card-pad space-y-4">
                            <div><h2 class="font-display text-xl font-semibold">{{ __('org_admin.global_roles') }}</h2><p class="mt-1 text-sm text-ink-500">{{ __('org_admin.global_roles_hint') }}</p></div>
                            <fieldset class="grid gap-2 sm:grid-cols-2" @disabled(! $canAssignGlobal)>
                                @foreach ($globalRoleOptions as $r)
                                    <label wire:key="gr-{{ $r->key }}" class="flex cursor-pointer items-center gap-3 rounded-xl border border-sand-100 px-4 py-3 text-sm transition hover:border-brass-300 has-[:checked]:border-brass-400 has-[:checked]:bg-brass-50/50 dark:border-ink-800 dark:has-[:checked]:bg-brass-500/10">
                                        <input type="checkbox" value="{{ $r->key }}" wire:model="globalRoles" class="checkbox">
                                        <span class="min-w-0 flex-1 truncate">{{ $r->localized('name') }}</span>
                                        @if ($r->is_system)<x-icon name="lock" class="size-3.5 text-ink-400" />@endif
                                    </label>
                                @endforeach
                            </fieldset>
                            @error('globalRoles')<p class="field-error">{{ $message }}</p>@enderror
                            @if ($canAssignGlobal)
                                <div class="flex justify-end"><button class="btn-primary" wire:loading.attr="disabled" wire:target="saveGlobalRoles"><x-icon name="check" class="size-4" />{{ __('org_admin.save_global_roles') }}</button></div>
                            @endif
                        </form>
                    @endif
                </div>

                @if (! $isSelf && $assignableBranches->isNotEmpty())
                    <form wire:submit="saveAssignment" class="card card-pad h-fit space-y-4 lg:sticky lg:top-24">
                        <h3 class="font-display text-lg font-semibold">{{ $assign['branch_id'] && $employee->branchAssignments->contains('branch_id', $assign['branch_id']) ? __('org_admin.update_assignment') : __('org_admin.assign_to_branch') }}</h3>
                        <x-admin.field :label="__('admin.branch')" for="assign.branch_id" required>
                            <select id="assign.branch_id" wire:model="assign.branch_id" class="input pe-10 rtl:[background-position:left_0.5rem_center]">
                                <option value="">—</option>
                                @foreach ($assignableBranches as $b)<option value="{{ $b->id }}">{{ $b->code }} · {{ $b->localized('name') }}</option>@endforeach
                            </select>
                        </x-admin.field>
                        <x-admin.field :label="__('admin.roles')" error="assign.roles">
                            <div class="space-y-1.5">
                                @foreach ($branchRoles as $r)
                                    <label wire:key="br-{{ $r->key }}" class="flex items-center gap-2.5 rounded-lg px-2 py-1.5 text-sm hover:bg-sand-50 dark:hover:bg-ink-800"><input type="checkbox" value="{{ $r->key }}" wire:model="assign.roles" class="checkbox">{{ $r->localized('name') }}</label>
                                @endforeach
                            </div>
                        </x-admin.field>
                        <x-admin.field :label="__('org_admin.job_title')" for="assign.job_title"><input id="assign.job_title" wire:model="assign.job_title" class="input"></x-admin.field>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="assign.is_delivery_driver" class="checkbox">{{ __('org_admin.is_driver') }}</label>
                        <p class="text-xs leading-relaxed text-ink-500">{{ __('org_admin.authority_hint') }}</p>
                        <div class="flex gap-2">
                            @if ($assign['branch_id'])<button type="button" wire:click="$set('assign', {branch_id: '', roles: [], job_title: '', is_delivery_driver: false})" class="btn-secondary">{{ __('admin.cancel') }}</button>@endif
                            <button class="btn-primary flex-1" wire:loading.attr="disabled" wire:target="saveAssignment"><x-icon name="check" class="size-4" />{{ __('admin.save') }}</button>
                        </div>
                    </form>
                @endif
            </div>

        @elseif ($tab === 'security')
            <div class="grid gap-6 [&>*]:min-w-0 lg:grid-cols-2">
                <div class="card card-pad space-y-4">
                    <h2 class="font-display text-xl font-semibold">{{ __('org_admin.account_status') }}</h2>
                    <p class="text-sm text-ink-500">{{ __('org_admin.account_status_hint') }}</p>
                    <div class="grid gap-2">
                        @foreach (['active' => ['check', 'org_admin.activate', 'org_admin.activate_hint'], 'suspended' => ['pause', 'org_admin.suspend', 'org_admin.suspend_hint'], 'deactivated' => ['ban', 'org_admin.deactivate', 'org_admin.deactivate_hint']] as $st => [$icon, $label, $hint])
                            @php $current = $employee->status->value === $st; @endphp
                            <button type="button" wire:click="setStatus('{{ $st }}')" @if ($st !== 'active') wire:confirm="{{ __('org_admin.confirm_status_'.$st) }}" @endif
                                    @disabled($current || ! $canManage)
                                    class="flex items-center gap-4 rounded-xl border px-4 py-3 text-start transition disabled:cursor-default {{ $current ? 'border-brass-400 bg-brass-50/60 dark:bg-brass-500/10' : 'border-sand-100 hover:border-sand-300 disabled:opacity-50 dark:border-ink-800' }}">
                                <span class="grid size-9 shrink-0 place-items-center rounded-lg {{ $st === 'active' ? 'bg-emerald-soft/10 text-emerald-soft' : ($st === 'suspended' ? 'bg-amber-soft/10 text-amber-soft' : 'bg-ruby-soft/10 text-ruby-soft') }}"><x-icon :name="$icon" class="size-4" /></span>
                                <span class="min-w-0 flex-1"><span class="block text-sm font-medium">{{ __($label) }}</span><span class="block text-xs text-ink-500">{{ __($hint) }}</span></span>
                                @if ($current)<span class="badge-brass">{{ __('org_admin.current') }}</span>@endif
                            </button>
                        @endforeach
                    </div>
                </div>
                <form wire:submit="resetPassword" class="card card-pad h-fit space-y-4">
                    <h2 class="font-display text-xl font-semibold">{{ __('org_admin.reset_password') }}</h2>
                    <p class="text-sm text-ink-500">{{ __('org_admin.reset_password_hint') }}</p>
                    <x-admin.field :label="__('admin.new_password')" for="newPassword" :hint="__('admin.password_policy')">
                        <div class="flex gap-2">
                            <input id="newPassword" type="{{ $showPassword ? 'text' : 'password' }}" wire:model="newPassword" class="input font-mono" dir="ltr" autocomplete="new-password" @disabled(! $canManage)>
                            <button type="button" wire:click="generatePassword" class="btn-secondary shrink-0 px-3" @disabled(! $canManage) aria-label="{{ __('org_admin.generate') }}" title="{{ __('org_admin.generate') }}"><x-icon name="sparkles" class="size-4" /></button>
                        </div>
                    </x-admin.field>
                    @if ($canManage)
                        <button class="btn-primary w-full" wire:confirm="{{ __('org_admin.confirm_reset_password') }}" wire:loading.attr="disabled" wire:target="resetPassword"><x-icon name="key" class="size-4" />{{ __('org_admin.reset_password') }}</button>
                    @endif
                </form>
            </div>

        @else
            <div class="card overflow-hidden">
                <div class="flex items-center justify-between p-5 sm:p-6">
                    <h2 class="font-display text-xl font-semibold">{{ __('org_admin.tab_activity') }}</h2>
                    @if ($this->can('audit.view'))<a href="{{ route('admin.audit.index', ['subject' => 'User:'.$employee->id]) }}" wire:navigate class="btn-ghost btn-sm">{{ __('admin.view_all') }}<x-icon name="arrow-right" class="size-4 rtl-flip" /></a>@endif
                </div>
                @forelse ($recentActivity as $log)
                    <div class="flex items-start gap-4 border-t border-sand-100 px-5 py-3.5 sm:px-6 dark:border-ink-800">
                        <span class="mt-0.5 grid size-8 shrink-0 place-items-center rounded-lg bg-sand-100 text-ink-600 dark:bg-ink-800 dark:text-sand-200"><x-icon name="history" class="size-4" /></span>
                        <div class="min-w-0 flex-1">
                            <x-admin.audit-action :action="$log->action" class="block text-sm font-medium" />
                            <p class="text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($log->created_at) }} · {{ $log->actor?->name ?? __('org_admin.system') }}</p>
                        </div>
                    </div>
                @empty
                    <x-admin.empty :title="__('org_admin.no_activity')" icon="history" class="!py-10" />
                @endforelse
            </div>
        @endif
    @endif
</div>
