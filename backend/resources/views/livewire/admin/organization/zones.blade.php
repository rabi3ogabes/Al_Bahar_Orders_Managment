<div>
    <x-admin.page-header :title="__('nav.zones')" :eyebrow="__('nav.organization')" :description="__('org_admin.zones_hint')">
        <x-slot:actions>
            @if ($canCreate)
                <button type="button" wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('org_admin.new_zone') }}</button>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('org_admin.zones_search')" />
            <select wire:model.live="filterStatus" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:w-48" aria-label="{{ __('admin.status') }}">
                <option value="">{{ __('admin.all_statuses') }}</option>
                <option value="active">{{ __('status.active') }}</option>
                <option value="inactive">{{ __('status.inactive') }}</option>
                <option value="remote">{{ __('org_admin.remote_only') }}</option>
            </select>
            <x-slot:meta>{{ trans_choice('org_admin.zones_count', $zones->total(), ['count' => $zones->total()]) }}</x-slot:meta>
        </x-admin.toolbar>

        @if ($zones->isEmpty())
            <x-admin.empty :title="__('org_admin.zones_empty')" :description="__('org_admin.zones_empty_hint')" icon="map">
                @if ($canCreate)<button type="button" wire:click="create" class="btn-brass"><x-icon name="plus" class="size-4" />{{ __('org_admin.new_zone') }}</button>@endif
            </x-admin.empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr>
                        <th>{{ __('org_admin.zone') }}</th><th>{{ __('org_admin.zone_numbers') }}</th><th>{{ __('org_admin.served_by') }}</th>
                        <th>{{ __('org_admin.coverage') }}</th><th>{{ __('admin.status') }}</th><th class="w-28"></th>
                    </tr></thead>
                    <tbody>
                    @foreach ($zones as $z)
                        @php $numbers = $z->zone_numbers ? explode(',', $z->zone_numbers) : []; @endphp
                        <tr wire:key="z-{{ $z->id }}">
                            <td>
                                <div class="flex items-center gap-3">
                                    <span class="grid size-10 shrink-0 place-items-center rounded-xl {{ $z->is_remote ? 'bg-amber-soft/10 text-amber-soft' : 'bg-sand-100 text-ink-600 dark:bg-ink-800 dark:text-sand-200' }}"><x-icon name="map" class="size-4" /></span>
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium text-ink-900 dark:text-ivory">{{ $z->localized('name') }}</span>
                                        <span class="block font-mono text-xs text-ink-500" dir="ltr">{{ $z->code }}</span>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div class="flex max-w-xs flex-wrap gap-1" dir="ltr">
                                    @forelse (array_slice($numbers, 0, 8) as $n)<span class="rounded-md bg-sand-100 px-1.5 py-0.5 font-mono text-[0.7rem] text-ink-700 tabular-nums dark:bg-ink-800 dark:text-sand-200">{{ $n }}</span>@empty<span class="text-ink-400">—</span>@endforelse
                                    @if (count($numbers) > 8)<span class="rounded-md px-1.5 py-0.5 text-[0.7rem] text-ink-500">+{{ count($numbers) - 8 }}</span>@endif
                                </div>
                            </td>
                            <td>
                                <div class="flex flex-wrap gap-1.5">
                                    @forelse ($z->branchConfigs as $c)
                                        <span class="{{ $c->is_active ? 'badge-brass' : 'badge-neutral opacity-60' }}" title="{{ $c->branch->localized('name') }} · {{ \App\Support\Money::of($c->delivery_fee)->format() }}">
                                            <span class="font-mono" dir="ltr">{{ $c->branch->code }}</span><span class="tabular-nums opacity-80">{{ \App\Support\Money::of($c->delivery_fee)->format() }}</span>
                                        </span>
                                    @empty
                                        <span class="badge-warning"><x-icon name="alert" class="size-3" />{{ __('org_admin.not_served') }}</span>
                                    @endforelse
                                </div>
                            </td>
                            <td>
                                <div class="flex flex-wrap gap-1.5">
                                    @if ($z->polygon)<span class="badge-info"><x-icon name="map-pin" class="size-3" />{{ __('org_admin.polygon_points', ['n' => count($z->polygon)]) }}</span>@endif
                                    @if ($z->is_remote)<span class="badge-warning">{{ __('org_admin.remote') }}</span>@endif
                                    @if (! $z->polygon && ! $z->is_remote)<span class="text-ink-400">—</span>@endif
                                </div>
                            </td>
                            <td><x-admin.status :value="$z->is_active ? 'active' : 'inactive'" /></td>
                            <td>
                                <div class="flex justify-end gap-1">
                                    @if ($canEdit)
                                        <button type="button" wire:click="edit('{{ $z->id }}')" class="btn-ghost btn-sm" aria-label="{{ __('admin.edit') }}"><x-icon name="edit" class="size-4" /></button>
                                        <button type="button" wire:click="toggleActive('{{ $z->id }}')" class="btn-ghost btn-sm" aria-label="{{ $z->is_active ? __('admin.disable') : __('admin.enable') }}" title="{{ $z->is_active ? __('admin.disable') : __('admin.enable') }}"><x-icon :name="$z->is_active ? 'pause' : 'play'" class="size-4" /></button>
                                    @endif
                                    @if ($canDelete)
                                        <button type="button" wire:click="delete('{{ $z->id }}')" wire:confirm="{{ __('org_admin.confirm_delete_zone') }}" class="btn-ghost btn-sm text-ruby-soft" aria-label="{{ __('admin.delete') }}"><x-icon name="trash" class="size-4" /></button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($zones->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $zones->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>

    <x-admin.dialog wire:model="showEditor" :title="$editingId ? __('org_admin.edit_zone') : __('org_admin.new_zone')" :description="__('org_admin.zone_editor_hint')" max-width="max-w-2xl">
        <form wire:submit="save" class="space-y-5">
            <div class="grid gap-5 sm:grid-cols-3">
                <x-admin.field :label="__('org_admin.code')" for="form.code" required>
                    <input id="form.code" wire:model="form.code" class="input font-mono uppercase" dir="ltr" maxlength="32" placeholder="WESTBAY">
                </x-admin.field>
                <x-admin.field :label="__('org_admin.name_en')" for="form.name_en" required class="sm:col-span-2">
                    <input id="form.name_en" wire:model="form.name_en" class="input" dir="ltr">
                </x-admin.field>
            </div>
            <x-admin.field :label="__('org_admin.name_ar')" for="form.name_ar" required>
                <input id="form.name_ar" wire:model="form.name_ar" class="input" dir="rtl" lang="ar">
            </x-admin.field>
            <x-admin.field :label="__('org_admin.zone_numbers')" for="form.zone_numbers" :hint="__('org_admin.zone_numbers_hint')">
                <input id="form.zone_numbers" wire:model="form.zone_numbers" class="input font-mono" dir="ltr" placeholder="60, 61, 62, 63">
            </x-admin.field>
            <x-admin.field :label="__('org_admin.polygon')" for="form.polygon" :hint="__('org_admin.polygon_hint')">
                <textarea id="form.polygon" wire:model="form.polygon" rows="5" class="input font-mono text-xs" dir="ltr" placeholder='[[51.52, 25.31], [51.54, 25.31], [51.54, 25.33], [51.52, 25.33]]'></textarea>
            </x-admin.field>
            <div class="grid gap-3 sm:grid-cols-2">
                <x-admin.toggle :label="__('org_admin.remote')" :hint="__('org_admin.remote_hint')" wire:model="form.is_remote" />
                <x-admin.toggle :label="__('status.active')" :hint="__('org_admin.zone_active_hint')" wire:model="form.is_active" />
            </div>
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary" @click="requestClose()">{{ __('admin.cancel') }}</button>
                <button class="btn-primary" wire:loading.attr="disabled" wire:target="save"><x-icon name="check" class="size-4" />{{ __('admin.save') }}</button>
            </div>
        </form>
    </x-admin.dialog>
</div>
