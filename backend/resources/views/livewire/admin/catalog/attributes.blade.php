<div>
    <x-admin.page-header :title="__('nav.attributes')" :eyebrow="__('nav.catalog')" :description="__('catalog_admin.attributes_hint')">
        <x-slot:actions>
            @if ($canCreate)<button type="button" wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('catalog_admin.new_attribute') }}</button>@endif
        </x-slot:actions>
    </x-admin.page-header>

    @if ($attributes->isEmpty())
        <div class="card"><x-admin.empty :title="__('catalog_admin.attributes_empty')" :description="__('catalog_admin.attributes_empty_hint')" icon="tag" /></div>
    @else
        <div class="grid gap-6 [&>*]:min-w-0 lg:grid-cols-3">
            <div class="card h-fit overflow-hidden">
                <ul class="divide-y divide-sand-100 dark:divide-ink-800">
                    @foreach ($attributes as $a)
                        <li wire:key="a-{{ $a->id }}">
                            <button type="button" wire:click="select({{ $a->id }})" class="flex w-full items-center gap-3 px-5 py-4 text-start transition {{ $current?->id === $a->id ? 'bg-sand-50 shadow-[inset_3px_0_0_var(--color-brass-500)] rtl:shadow-[inset_-3px_0_0_var(--color-brass-500)] dark:bg-ink-800/60' : 'hover:bg-sand-50/60 dark:hover:bg-ink-800/30' }}">
                                <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-sand-100 text-ink-600 dark:bg-ink-800 dark:text-sand-200"><x-icon :name="$a->type === 'color' ? 'sparkles' : ($a->type === 'text' ? 'file-text' : 'tag')" class="size-4" /></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-medium text-ink-900 dark:text-ivory">{{ $a->localized('name') }}</span>
                                    <span class="block font-mono text-xs text-ink-500" dir="ltr">{{ $a->code }}</span>
                                </span>
                                <span class="badge-neutral tabular-nums">{{ $a->values_count }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>

            @if ($current)
                <div class="min-w-0 space-y-6 lg:col-span-2">
                    <div class="card card-pad">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="eyebrow mb-1">{{ __('catalog_admin.type_'.$current->type) }}</p>
                                <h2 class="font-display text-2xl font-semibold">{{ $current->localized('name') }}</h2>
                                <div class="mt-3 flex flex-wrap gap-1.5">
                                    @if ($current->is_variant_axis)<span class="badge-brass">{{ __('catalog_admin.variant_axis') }}</span>@endif
                                    @if ($current->is_filterable)<span class="badge-info">{{ __('catalog_admin.filterable') }}</span>@endif
                                </div>
                            </div>
                            <div class="flex gap-1">
                                @if ($canEdit)<button type="button" wire:click="edit({{ $current->id }})" class="btn-secondary btn-sm"><x-icon name="edit" class="size-3.5" />{{ __('admin.edit') }}</button>@endif
                                @if ($canDelete)<button type="button" wire:click="deleteAttribute({{ $current->id }})" wire:confirm="{{ __('catalog_admin.confirm_delete_attribute') }}" class="btn-ghost btn-sm text-ruby-soft" aria-label="{{ __('admin.delete') }}"><x-icon name="trash" class="size-4" /></button>@endif
                            </div>
                        </div>
                    </div>

                    <div class="card overflow-hidden">
                        <div class="p-5 sm:p-6"><h3 class="font-display text-xl font-semibold">{{ __('catalog_admin.values') }}</h3><p class="mt-1 text-sm text-ink-500">{{ __('catalog_admin.values_hint') }}</p></div>
                        @if ($values->isEmpty())
                            <x-admin.empty :title="__('catalog_admin.values_empty')" icon="tag" class="!py-10" />
                        @else
                            <div class="table-wrap">
                                <table class="table">
                                    <thead><tr><th>{{ __('catalog_admin.value') }}</th><th>{{ __('catalog_admin.code') }}</th><th class="!text-end">{{ __('catalog_admin.used_by') }}</th><th class="w-40"></th></tr></thead>
                                    <tbody>
                                    @foreach ($values as $v)
                                        <tr wire:key="v-{{ $v->id }}" class="{{ $editingValueId === $v->id ? 'bg-brass-50/50 dark:bg-brass-500/10' : '' }}">
                                            <td>
                                                <div class="flex items-center gap-3">
                                                    @if ($v->color_hex)<span class="size-7 shrink-0 rounded-full border border-black/10 shadow-inner" style="background-color: {{ $v->color_hex }}" title="{{ $v->color_hex }}"></span>@endif
                                                    <span><span class="block font-medium">{{ $v->value_en }}</span><span class="block text-xs text-ink-500" dir="rtl" lang="ar">{{ $v->value_ar }}</span></span>
                                                </div>
                                            </td>
                                            <td class="font-mono text-xs text-ink-500" dir="ltr">{{ $v->code }}</td>
                                            <td class="text-end tabular-nums text-ink-500">{{ $usage[$v->id] ?? 0 }}</td>
                                            <td>
                                                @if ($canEdit)
                                                    <div class="flex justify-end gap-0.5">
                                                        <button type="button" wire:click="moveValue({{ $v->id }}, -1)" class="btn-ghost btn-sm px-2" @disabled($loop->first) aria-label="{{ __('catalog_admin.move_up') }}"><x-icon name="chevron-down" class="size-4 rotate-180" /></button>
                                                        <button type="button" wire:click="moveValue({{ $v->id }}, 1)" class="btn-ghost btn-sm px-2" @disabled($loop->last) aria-label="{{ __('catalog_admin.move_down') }}"><x-icon name="chevron-down" class="size-4" /></button>
                                                        <button type="button" wire:click="editValue({{ $v->id }})" class="btn-ghost btn-sm px-2" aria-label="{{ __('admin.edit') }}"><x-icon name="edit" class="size-4" /></button>
                                                        @if ($canDelete)<button type="button" wire:click="deleteValue({{ $v->id }})" wire:confirm="{{ __('admin.confirm_delete') }}" class="btn-ghost btn-sm px-2 text-ruby-soft" aria-label="{{ __('admin.delete') }}"><x-icon name="trash" class="size-4" /></button>@endif
                                                    </div>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                        @if ($canEdit)
                            <form wire:submit="saveValue" class="border-t border-sand-100 bg-sand-50/40 p-5 sm:p-6 dark:border-ink-800 dark:bg-ink-800/20">
                                <p class="mb-4 text-sm font-medium">{{ $editingValueId ? __('catalog_admin.edit_value') : __('catalog_admin.add_value') }}</p>
                                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                    <x-admin.field :label="__('catalog_admin.value_en')" for="valueForm.value_en" required><input id="valueForm.value_en" wire:model.blur="valueForm.value_en" class="input" dir="ltr" placeholder="Walnut"></x-admin.field>
                                    <x-admin.field :label="__('catalog_admin.value_ar')" for="valueForm.value_ar" required><input id="valueForm.value_ar" wire:model="valueForm.value_ar" class="input" dir="rtl" lang="ar" placeholder="جوز"></x-admin.field>
                                    <x-admin.field :label="__('catalog_admin.code')" for="valueForm.code" required><input id="valueForm.code" wire:model="valueForm.code" class="input font-mono" dir="ltr"></x-admin.field>
                                    @if ($current->type === 'color')
                                        <x-admin.field :label="__('catalog_admin.swatch')" for="valueForm.color_hex" required>
                                            <div class="flex gap-2" x-data>
                                                <input type="color" class="h-[2.625rem] w-12 shrink-0 cursor-pointer rounded-xl border border-sand-300 bg-white p-1 dark:border-ink-600 dark:bg-ink-800" :value="$wire.valueForm.color_hex || '#b08d57'" @input="$wire.set('valueForm.color_hex', $event.target.value, false)" aria-label="{{ __('catalog_admin.swatch') }}">
                                                <input id="valueForm.color_hex" wire:model="valueForm.color_hex" class="input font-mono" dir="ltr" placeholder="#6b4f3a">
                                            </div>
                                        </x-admin.field>
                                    @else
                                        <x-admin.field :label="__('catalog_admin.sort_order')" for="valueForm.sort_order"><input id="valueForm.sort_order" type="number" min="0" wire:model="valueForm.sort_order" class="input tabular-nums"></x-admin.field>
                                    @endif
                                </div>
                                <div class="mt-4 flex justify-end gap-2">
                                    @if ($editingValueId)<button type="button" wire:click="cancelValue" class="btn-secondary">{{ __('admin.cancel') }}</button>@endif
                                    <button class="btn-primary" wire:loading.attr="disabled" wire:target="saveValue"><x-icon :name="$editingValueId ? 'check' : 'plus'" class="size-4" />{{ $editingValueId ? __('admin.save') : __('catalog_admin.add_value') }}</button>
                                </div>
                            </form>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    @endif

    <x-admin.dialog wire:model="showEditor" :title="$editingId ? __('catalog_admin.edit_attribute') : __('catalog_admin.new_attribute')">
        <form wire:submit="save" class="space-y-5">
            <x-admin.field :label="__('catalog_admin.name_en')" for="form.name_en" required><input id="form.name_en" wire:model="form.name_en" class="input" dir="ltr"></x-admin.field>
            <x-admin.field :label="__('catalog_admin.name_ar')" for="form.name_ar" required><input id="form.name_ar" wire:model="form.name_ar" class="input" dir="rtl" lang="ar"></x-admin.field>
            <div class="grid gap-5 sm:grid-cols-3">
                <x-admin.field :label="__('catalog_admin.code')" for="form.code" required class="sm:col-span-2"><input id="form.code" wire:model="form.code" class="input font-mono" dir="ltr" placeholder="material"></x-admin.field>
                <x-admin.field :label="__('catalog_admin.sort_order')" for="form.sort_order"><input id="form.sort_order" type="number" min="0" wire:model="form.sort_order" class="input tabular-nums"></x-admin.field>
            </div>
            <x-admin.field :label="__('catalog_admin.attribute_type')" for="form.type">
                <select id="form.type" wire:model="form.type" class="input pe-10 rtl:[background-position:left_0.5rem_center]">
                    @foreach (['select', 'color', 'text'] as $t)<option value="{{ $t }}">{{ __('catalog_admin.type_'.$t) }}</option>@endforeach
                </select>
            </x-admin.field>
            <div class="grid gap-3 sm:grid-cols-2">
                <x-admin.toggle :label="__('catalog_admin.variant_axis')" :hint="__('catalog_admin.variant_axis_hint')" wire:model="form.is_variant_axis" />
                <x-admin.toggle :label="__('catalog_admin.filterable')" :hint="__('catalog_admin.filterable_hint')" wire:model="form.is_filterable" />
            </div>
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary" @click="requestClose()">{{ __('admin.cancel') }}</button>
                <button class="btn-primary" wire:loading.attr="disabled" wire:target="save"><x-icon name="check" class="size-4" />{{ __('admin.save') }}</button>
            </div>
        </form>
    </x-admin.dialog>
</div>
