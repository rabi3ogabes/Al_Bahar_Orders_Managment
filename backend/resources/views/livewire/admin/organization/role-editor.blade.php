@php $isNew = ! $role; $ar = app()->getLocale() === 'ar'; @endphp
<div class="mx-auto max-w-7xl">
    <a href="{{ route('admin.roles.index') }}" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-ink-500 hover:text-brass-600"><x-icon name="chevron-left" class="size-4 rtl-flip" />{{ __('nav.roles') }}</a>
    <x-admin.page-header :title="$isNew ? __('org_admin.new_role') : $role->localized('name')" :eyebrow="$isNew ? __('nav.organization') : $role->key" :description="__('org_admin.role_editor_hint')">
        <x-slot:actions>
            @unless ($isNew)
                @if ($role->is_system)<span class="badge-neutral"><x-icon name="lock" class="size-3" />{{ __('org_admin.system_role') }}</span>@endif
                <span class="{{ $role->scope === 'global' ? 'badge-info' : 'badge-neutral' }}">{{ __('org_admin.scope_'.$role->scope) }}</span>
            @endunless
            @if ($canDelete)
                <button type="button" wire:click="delete" wire:confirm="{{ __('org_admin.confirm_delete_role') }}" class="btn-ghost text-ruby-soft"><x-icon name="trash" class="size-4" />{{ __('admin.delete') }}</button>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    @if ($locked)
        <div class="mb-6 flex items-start gap-3 rounded-2xl border border-brass-300/40 bg-brass-50/60 p-4 text-sm text-ink-700 dark:bg-brass-500/10 dark:text-sand-200">
            <x-icon name="crown" class="mt-0.5 size-4 shrink-0 text-brass-600" /><p>{{ __('org_admin.super_admin_locked') }}</p>
        </div>
    @endif

    <form wire:submit="save" class="space-y-6">
        <div class="grid gap-6 [&>*]:min-w-0 lg:grid-cols-3">
            <div class="min-w-0 card card-pad space-y-5 lg:col-span-2">
                <h2 class="font-display text-xl font-semibold">{{ __('org_admin.role_identity') }}</h2>
                <fieldset class="space-y-5" @disabled(! $canSave)>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-admin.field :label="__('org_admin.name_en')" for="form.name_en" required><input id="form.name_en" wire:model="form.name_en" class="input" dir="ltr"></x-admin.field>
                        <x-admin.field :label="__('org_admin.name_ar')" for="form.name_ar" required><input id="form.name_ar" wire:model="form.name_ar" class="input" dir="rtl" lang="ar"></x-admin.field>
                        <x-admin.field :label="__('org_admin.role_key')" for="form.key" :required="$isNew" :hint="$isNew ? __('org_admin.role_key_hint') : null">
                            <input id="form.key" wire:model="form.key" class="input font-mono" dir="ltr" @disabled(! $isNew) placeholder="showroom_supervisor">
                        </x-admin.field>
                        <x-admin.field :label="__('org_admin.scope')" for="form.scope" :hint="__('org_admin.scope_hint')">
                            <select id="form.scope" wire:model="form.scope" class="input pe-10 rtl:[background-position:left_0.5rem_center]" @disabled(! $isNew)>
                                <option value="branch">{{ __('org_admin.scope_branch') }}</option>
                                <option value="global">{{ __('org_admin.scope_global') }}</option>
                            </select>
                        </x-admin.field>
                    </div>
                    <x-admin.field :label="__('org_admin.description')" for="form.description"><textarea id="form.description" wire:model="form.description" rows="2" class="input"></textarea></x-admin.field>
                </fieldset>
            </div>
            <div class="card card-pad space-y-4">
                <h2 class="font-display text-xl font-semibold">{{ __('org_admin.members') }}</h2>
                @forelse ($members as $m)
                    <div class="flex items-center gap-3 text-sm" wire:key="m-{{ $m->id }}">
                        <span class="grid size-8 shrink-0 place-items-center rounded-full bg-sand-100 text-xs font-semibold dark:bg-ink-800">{{ mb_substr($m->user->name, 0, 1) }}</span>
                        <span class="min-w-0 flex-1 truncate">{{ $m->user->name }}</span>
                        <span class="badge-neutral font-mono" dir="ltr">{{ $m->branch?->code ?? __('org_admin.all_short') }}</span>
                    </div>
                @empty
                    <p class="text-sm text-ink-500">{{ __('org_admin.no_members') }}</p>
                @endforelse
            </div>
        </div>

        <div class="card overflow-hidden">
            <div class="flex flex-col gap-3 p-5 sm:flex-row sm:items-end sm:justify-between sm:p-6">
                <div>
                    <h2 class="font-display text-xl font-semibold">{{ __('org_admin.permission_matrix') }}</h2>
                    <p class="mt-1 text-sm text-ink-500">{{ __('org_admin.permission_matrix_hint') }}</p>
                </div>
                <span class="badge-brass tabular-nums">{{ __('org_admin.granted_count', ['n' => $grantedCount]) }}</span>
            </div>
            <div class="table-wrap border-t border-sand-100 dark:border-ink-800">
                <table class="table">
                    <thead><tr>
                        <th class="sticky start-0 z-10 min-w-[12rem] !bg-sand-50 dark:!bg-ink-800">{{ __('org_admin.module') }}</th>
                        @foreach ($actions as $action)
                            <th class="!text-center">
                                @if ($canSave)
                                    <button type="button" wire:click="toggleAction('{{ $action }}')" class="rounded-md px-1.5 py-0.5 uppercase hover:bg-sand-100 hover:text-ink-900 dark:hover:bg-ink-700" title="{{ __('org_admin.toggle_column') }}">{{ __('org_admin.action_'.$action) }}</button>
                                @else{{ __('org_admin.action_'.$action) }}@endif
                            </th>
                        @endforeach
                    </tr></thead>
                    <tbody>
                    @foreach ($modules as $module => [$moduleActions, $en, $arName])
                        <tr wire:key="mod-{{ $module }}">
                            <td class="sticky start-0 z-10 bg-white dark:bg-ink-900">
                                @if ($canSave)
                                    <button type="button" wire:click="toggleModule('{{ $module }}')" class="text-start font-medium text-ink-900 hover:text-brass-600 dark:text-ivory" title="{{ __('org_admin.toggle_row') }}">{{ $ar ? $arName : $en }}</button>
                                @else<span class="font-medium">{{ $ar ? $arName : $en }}</span>@endif
                                <span class="block font-mono text-[0.7rem] text-ink-400" dir="ltr">{{ $module }}</span>
                            </td>
                            @foreach ($actions as $action)
                                <td class="text-center">
                                    @if (in_array($action, $moduleActions, true))
                                        @php $key = $module.'.'.$action; $editable = $canSave && isset($grantableKeys[$key]); @endphp
                                        <input type="checkbox" wire:model.live="granted.{{ $module }}.{{ $action }}" class="checkbox size-[1.1rem] disabled:opacity-40" @disabled(! $editable)
                                               aria-label="{{ $key }}" title="{{ $editable ? $key : __('org_admin.not_grantable') }}">
                                    @else
                                        <span class="text-ink-300 dark:text-ink-700" aria-hidden="true">·</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        @if ($canSave)
            <div class="sticky bottom-4 z-20 flex justify-end">
                <div class="card flex items-center gap-3 p-2 shadow-[var(--shadow-lift)]">
                    <span class="hidden px-3 text-sm text-ink-500 sm:inline">{{ __('org_admin.changes_audited') }}</span>
                    <button class="btn-primary" wire:loading.attr="disabled" wire:target="save"><x-icon name="check" class="size-4" />{{ $isNew ? __('org_admin.create_role') : __('admin.save_changes') }}</button>
                </div>
            </div>
        @endif
    </form>
</div>
