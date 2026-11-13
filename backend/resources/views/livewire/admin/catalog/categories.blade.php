@php $url = fn ($p) => \App\Livewire\Admin\Catalog\CategoryIndex::mediaUrl($p); @endphp
<div>
    <x-admin.page-header :title="__('nav.categories')" :eyebrow="__('nav.catalog')" :description="__('catalog_admin.categories_hint')">
        <x-slot:actions>
            @if ($canCreate)<button type="button" wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('catalog_admin.new_category') }}</button>@endif
        </x-slot:actions>
    </x-admin.page-header>

    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('catalog_admin.categories_search')" />
            <x-slot:meta>{{ trans_choice('catalog_admin.categories_count', $total, ['count' => $total]) }}</x-slot:meta>
        </x-admin.toolbar>

        @if ($rows->isEmpty())
            <x-admin.empty :title="__('catalog_admin.categories_empty')" :description="__('catalog_admin.categories_empty_hint')" icon="layers">
                @if ($canCreate && ! $search)<button type="button" wire:click="create" class="btn-brass"><x-icon name="plus" class="size-4" />{{ __('catalog_admin.new_category') }}</button>@endif
            </x-admin.empty>
        @else
            <ul class="divide-y divide-sand-100 dark:divide-ink-800" role="tree">
                @foreach ($rows as $i => $row)
                    @php $c = $row['c']; $depth = $search ? 0 : $c->depth; @endphp
                    <li wire:key="c-{{ $c->id }}" role="treeitem" class="group flex flex-wrap items-center gap-x-3 gap-y-2 py-3 pe-4 transition [--indent:0.75rem] hover:bg-sand-50/60 sm:flex-nowrap sm:[--indent:1.75rem] dark:hover:bg-ink-800/30" style="padding-inline-start: calc(1rem + {{ $depth }} * var(--indent))">
                        @if ($row['children'])
                            <button type="button" wire:click="toggleCollapse('{{ $c->id }}')" class="grid size-6 shrink-0 place-items-center rounded-md text-ink-400 hover:bg-sand-100 hover:text-ink-700 dark:hover:bg-ink-800" aria-label="{{ __('catalog_admin.toggle_children') }}" aria-expanded="{{ in_array($c->id, $collapsed, true) ? 'false' : 'true' }}">
                                <x-icon name="chevron-down" class="size-4 transition {{ in_array($c->id, $collapsed, true) ? '-rotate-90 rtl:rotate-90' : '' }}" />
                            </button>
                        @else
                            <span class="size-6 shrink-0"></span>
                        @endif
                        <span class="grid size-10 shrink-0 place-items-center overflow-hidden rounded-xl bg-sand-100 text-ink-500 dark:bg-ink-800">
                            @if ($c->icon_path || $c->image_path)<img src="{{ $url($c->icon_path ?: $c->image_path) }}" alt="" class="size-full object-cover" loading="lazy">@else<x-icon name="layers" class="size-4" />@endif
                        </span>
                        <div class="min-w-0 flex-1 basis-40">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                <span class="truncate font-medium text-ink-900 dark:text-ivory">{{ $c->trans('name') }}</span>
                                <span class="truncate text-xs text-ink-400" dir="{{ app()->getLocale() === 'ar' ? 'ltr' : 'rtl' }}" lang="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}">{{ $c->trans('name', app()->getLocale() === 'ar' ? 'en' : 'ar') }}</span>
                            </div>
                            <div class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-ink-500">
                                <span class="font-mono" dir="ltr">{{ $c->slug }}</span>
                                <span>· {{ trans_choice('catalog_admin.products_count', $c->products_count, ['count' => $c->products_count]) }}</span>
                                @if ($c->filter_attribute_ids)<span>· {{ trans_choice('catalog_admin.filters_count', count($c->filter_attribute_ids), ['count' => count($c->filter_attribute_ids)]) }}</span>@endif
                            </div>
                        </div>
                        @if ($c->status !== 'active')<x-admin.status :value="$c->status" />@endif
                        <div class="ms-auto flex shrink-0 items-center gap-0.5 opacity-100 transition sm:opacity-60 sm:group-hover:opacity-100 sm:focus-within:opacity-100">
                            @if ($canEdit && ! $search)
                                <button type="button" wire:click="move('{{ $c->id }}', -1)" class="btn-ghost btn-sm px-2" aria-label="{{ __('catalog_admin.move_up') }}"><x-icon name="chevron-down" class="size-4 rotate-180" /></button>
                                <button type="button" wire:click="move('{{ $c->id }}', 1)" class="btn-ghost btn-sm px-2" aria-label="{{ __('catalog_admin.move_down') }}"><x-icon name="chevron-down" class="size-4" /></button>
                            @endif
                            @if ($canCreate)<button type="button" wire:click="create('{{ $c->id }}')" class="btn-ghost btn-sm px-2" aria-label="{{ __('catalog_admin.add_subcategory') }}" title="{{ __('catalog_admin.add_subcategory') }}"><x-icon name="plus" class="size-4" /></button>@endif
                            @if ($canEdit)<button type="button" wire:click="edit('{{ $c->id }}')" class="btn-ghost btn-sm px-2" aria-label="{{ __('admin.edit') }}"><x-icon name="edit" class="size-4" /></button>@endif
                            @if ($canDelete)<button type="button" wire:click="delete('{{ $c->id }}')" wire:confirm="{{ __('catalog_admin.confirm_delete_category') }}" class="btn-ghost btn-sm px-2 text-ruby-soft" aria-label="{{ __('admin.delete') }}"><x-icon name="trash" class="size-4" /></button>@endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <x-admin.dialog wire:model="showEditor" :title="$editingId ? __('catalog_admin.edit_category') : __('catalog_admin.new_category')" max-width="max-w-3xl">
        <form wire:submit="save" class="space-y-5">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-admin.field :label="__('catalog_admin.name_en')" for="form.name_en" required><input id="form.name_en" wire:model="form.name_en" class="input" dir="ltr"></x-admin.field>
                <x-admin.field :label="__('catalog_admin.name_ar')" for="form.name_ar" required><input id="form.name_ar" wire:model="form.name_ar" class="input" dir="rtl" lang="ar"></x-admin.field>
                <x-admin.field :label="__('catalog_admin.parent')" for="form.parent_id" :hint="__('catalog_admin.parent_hint')">
                    <select id="form.parent_id" wire:model="form.parent_id" class="input pe-10 rtl:[background-position:left_0.5rem_center]">
                        <option value="">{{ __('catalog_admin.top_level') }}</option>
                        @foreach ($parentOptions as $p)<option value="{{ $p->id }}">{{ str_repeat('— ', $p->depth) }}{{ $p->trans('name') }}</option>@endforeach
                    </select>
                </x-admin.field>
                <x-admin.field :label="__('catalog_admin.slug')" for="form.slug" :required="(bool) $editingId" :hint="$editingId ? null : __('catalog_admin.slug_auto')"><input id="form.slug" wire:model="form.slug" class="input font-mono" dir="ltr"></x-admin.field>
                <x-admin.field :label="__('catalog_admin.description_en')" for="form.description_en"><textarea id="form.description_en" wire:model="form.description_en" rows="2" class="input" dir="ltr"></textarea></x-admin.field>
                <x-admin.field :label="__('catalog_admin.description_ar')" for="form.description_ar"><textarea id="form.description_ar" wire:model="form.description_ar" rows="2" class="input" dir="rtl" lang="ar"></textarea></x-admin.field>
                <x-admin.field :label="__('catalog_admin.seo_title_en')" for="form.seo_title_en"><input id="form.seo_title_en" wire:model="form.seo_title_en" class="input" dir="ltr"></x-admin.field>
                <x-admin.field :label="__('catalog_admin.seo_title_ar')" for="form.seo_title_ar"><input id="form.seo_title_ar" wire:model="form.seo_title_ar" class="input" dir="rtl" lang="ar"></x-admin.field>
            </div>

            <div>
                <p class="field-label">{{ __('catalog_admin.artwork') }}</p>
                <div class="grid grid-cols-3 gap-3">
                    <x-admin.image-input model="icon" :file="$icon" :current="$url($editing?->icon_path)" :hint="__('catalog_admin.icon')" />
                    <x-admin.image-input model="image" :file="$image" :current="$url($editing?->image_path)" :hint="__('catalog_admin.image')" />
                    <x-admin.image-input model="banner" :file="$banner" :current="$url($editing?->banner_path)" :hint="__('catalog_admin.banner')" />
                </div>
            </div>

            @if ($attributes->isNotEmpty())
                <x-admin.field :label="__('catalog_admin.filter_attributes')" :hint="__('catalog_admin.filter_attributes_hint')" error="form.filter_attribute_ids">
                    <div class="flex flex-wrap gap-2">
                        @foreach ($attributes as $a)
                            <label wire:key="fa-{{ $a->id }}" class="inline-flex cursor-pointer items-center gap-2 rounded-full border border-sand-200 px-3 py-1.5 text-sm transition has-[:checked]:border-brass-400 has-[:checked]:bg-brass-50 dark:border-ink-700 dark:has-[:checked]:bg-brass-500/15">
                                <input type="checkbox" value="{{ $a->id }}" wire:model="form.filter_attribute_ids" class="checkbox size-3.5">{{ $a->localized('name') }}
                            </label>
                        @endforeach
                    </div>
                </x-admin.field>
            @endif

            <x-admin.field :label="__('admin.status')" for="form.status">
                <select id="form.status" wire:model="form.status" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:w-48"><option value="active">{{ __('status.active') }}</option><option value="inactive">{{ __('status.inactive') }}</option></select>
            </x-admin.field>
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary" @click="requestClose()">{{ __('admin.cancel') }}</button>
                <button class="btn-primary" wire:loading.attr="disabled" wire:target="save,image,icon,banner"><x-icon name="check" class="size-4" />{{ __('admin.save') }}</button>
            </div>
        </form>
    </x-admin.dialog>
</div>
