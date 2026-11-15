<div>
    <x-admin.page-header :title="__('nav.roles')" :eyebrow="__('nav.organization')" :description="__('org_admin.roles_hint')">
        <x-slot:actions>
            @if ($canCreate)
                <x-admin.open-editor create :id="''" :href="route('admin.roles.create')" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('org_admin.new_role') }}</x-admin.open-editor>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('org_admin.roles_search')" />
            <select wire:model.live="filterScope" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:w-48" aria-label="{{ __('org_admin.scope') }}">
                <option value="">{{ __('org_admin.all_scopes') }}</option>
                <option value="global">{{ __('org_admin.scope_global') }}</option>
                <option value="branch">{{ __('org_admin.scope_branch') }}</option>
            </select>
            <x-slot:meta>{{ trans_choice('org_admin.roles_count', $roles->total(), ['count' => $roles->total()]) }}</x-slot:meta>
        </x-admin.toolbar>

        @if ($roles->isEmpty())
            <x-admin.empty :title="__('org_admin.roles_empty')" icon="shield" />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('org_admin.role') }}</th><th>{{ __('org_admin.scope') }}</th><th>{{ __('org_admin.permissions') }}</th><th class="!text-end">{{ __('org_admin.members') }}</th><th class="w-12"></th></tr></thead>
                    <tbody>
                    @foreach ($roles as $r)
                        @php $pct = $totalPermissions ? round($r->permissions_count * 100 / $totalPermissions) : 0; @endphp
                        <tr wire:key="r-{{ $r->id }}">
                            <td>
                                <x-admin.open-editor :id="(string) $r->id" :href="route('admin.roles.edit', $r)" class="group flex items-center gap-3">
                                    <span class="grid size-10 shrink-0 place-items-center rounded-xl {{ $r->key === 'super_admin' ? 'bg-brass-50 text-brass-600 dark:bg-brass-500/15 dark:text-brass-300' : 'bg-sand-100 text-ink-600 dark:bg-ink-800 dark:text-sand-200' }}"><x-icon :name="$r->key === 'super_admin' ? 'crown' : 'shield'" class="size-4" /></span>
                                    <span class="min-w-0">
                                        <span class="flex items-center gap-2 font-medium text-ink-900 group-hover:text-brass-600 dark:text-ivory">{{ $r->localized('name') }}
                                            @if ($r->is_system)<span class="badge-neutral"><x-icon name="lock" class="size-3" />{{ __('org_admin.system_role') }}</span>@endif</span>
                                        <span class="block font-mono text-xs text-ink-500" dir="ltr">{{ $r->key }}</span>
                                    </span>
                                </x-admin.open-editor>
                            </td>
                            <td><span class="{{ $r->scope === 'global' ? 'badge-info' : 'badge-neutral' }}"><x-icon :name="$r->scope === 'global' ? 'globe' : 'store'" class="size-3" />{{ __('org_admin.scope_'.$r->scope) }}</span></td>
                            <td>
                                <div class="flex min-w-[10rem] items-center gap-3">
                                    <div class="h-1.5 w-24 overflow-hidden rounded-full bg-sand-100 dark:bg-ink-800"><div class="h-full rounded-full bg-brass-500" style="width: {{ $pct }}%"></div></div>
                                    <span class="text-xs text-ink-500 tabular-nums">{{ $r->permissions_count }} / {{ $totalPermissions }}</span>
                                </div>
                            </td>
                            <td class="text-end tabular-nums">{{ $r->members_count }}</td>
                            <td><x-admin.open-editor :id="(string) $r->id" :href="route('admin.roles.edit', $r)" class="btn-ghost btn-sm" aria-label="{{ __('admin.edit') }}"><x-icon name="chevron-right" class="size-4 rtl-flip" /></x-admin.open-editor></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($roles->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $roles->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>

    {{-- Edit (or add) a role beside the list; the same editor opens as a full page from the window's "Open full page". --}}
    <x-admin.record-editor editor="admin.organization.role-editor" param="role" max-width="max-w-5xl"
        :editing="$editing" :record="$editRole" :creating="$creating"
        :title="$editRole?->localized('name')" :description="$editRole?->key"
        :full-url="$editRole ? route('admin.roles.edit', $editRole) : null"
        :new-title="__('org_admin.new_role')" :new-description="__('org_admin.role_editor_hint')" :new-url="$canCreate ? route('admin.roles.create') : null">
        @if ($editRole)
            <x-slot:badges>
                @if ($editRole->is_system)<span class="badge-neutral"><x-icon name="lock" class="size-3" />{{ __('org_admin.system_role') }}</span>@endif
                <span class="{{ $editRole->scope === 'global' ? 'badge-info' : 'badge-neutral' }}">{{ __('org_admin.scope_'.$editRole->scope) }}</span>
            </x-slot:badges>
        @endif
    </x-admin.record-editor>
</div>
