<div>
    <x-admin.page-header :title="__('nav.brands')" :eyebrow="__('nav.catalog')" :description="__('catalog_admin.brands_hint')">
        <x-slot:actions>
            @if ($canCreate)<button type="button" wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('catalog_admin.new_brand') }}</button>@endif
        </x-slot:actions>
    </x-admin.page-header>

    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('catalog_admin.brands_search')" />
            <select wire:model.live="filterStatus" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:w-44" aria-label="{{ __('admin.status') }}">
                <option value="">{{ __('admin.all_statuses') }}</option>
                <option value="active">{{ __('status.active') }}</option>
                <option value="inactive">{{ __('status.inactive') }}</option>
            </select>
            <x-slot:meta>{{ trans_choice('catalog_admin.brands_count', $brands->total(), ['count' => $brands->total()]) }}</x-slot:meta>
        </x-admin.toolbar>
        @if ($brands->isEmpty())
            <x-admin.empty :title="__('catalog_admin.brands_empty')" :description="__('catalog_admin.brands_empty_hint')" icon="crown">
                @if ($canCreate)<button type="button" wire:click="create" class="btn-brass"><x-icon name="plus" class="size-4" />{{ __('catalog_admin.new_brand') }}</button>@endif
            </x-admin.empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('catalog_admin.brand') }}</th><th class="!text-end">{{ __('nav.products') }}</th><th class="!text-end">{{ __('catalog_admin.sort_order') }}</th><th>{{ __('admin.status') }}</th><th class="w-24"></th></tr></thead>
                    <tbody>
                    @foreach ($brands as $b)
                        <tr wire:key="b-{{ $b->id }}">
                            <td>
                                <div class="flex items-center gap-3">
                                    <span class="grid size-11 shrink-0 place-items-center overflow-hidden rounded-xl border border-sand-100 bg-white dark:border-ink-700 dark:bg-ink-800">
                                        @if ($b->logo_path)<img src="{{ \App\Livewire\Admin\Catalog\BrandIndex::mediaUrl($b->logo_path) }}" alt="" class="size-full object-contain p-1" loading="lazy">@else<span class="font-display text-lg text-ink-400">{{ mb_substr($b->localized('name'), 0, 1) }}</span>@endif
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium text-ink-900 dark:text-ivory">{{ $b->localized('name') }}</span>
                                        <span class="block truncate font-mono text-xs text-ink-500" dir="ltr">{{ $b->slug }}</span>
                                    </span>
                                </div>
                            </td>
                            <td class="text-end tabular-nums"><a href="{{ route('admin.products.index', ['filterBrand' => $b->id]) }}" wire:navigate class="hover:text-brass-600">{{ $b->products_count }}</a></td>
                            <td class="text-end tabular-nums text-ink-500">{{ $b->sort_order }}</td>
                            <td><x-admin.status :value="$b->status" /></td>
                            <td>
                                <div class="flex justify-end gap-1">
                                    @if ($canEdit)<button type="button" wire:click="edit('{{ $b->id }}')" class="btn-ghost btn-sm" aria-label="{{ __('admin.edit') }}"><x-icon name="edit" class="size-4" /></button>@endif
                                    @if ($canDelete)<button type="button" wire:click="delete('{{ $b->id }}')" wire:confirm="{{ __('catalog_admin.confirm_delete_brand') }}" class="btn-ghost btn-sm text-ruby-soft" aria-label="{{ __('admin.delete') }}"><x-icon name="trash" class="size-4" /></button>@endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($brands->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $brands->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>

    <x-admin.dialog wire:model="showEditor" :title="$editingId ? __('catalog_admin.edit_brand') : __('catalog_admin.new_brand')" max-width="max-w-2xl">
        <form wire:submit="save" class="space-y-5">
            <div class="grid gap-5 sm:grid-cols-[8rem_1fr]">
                <x-admin.image-input model="logo" :file="$logo" :current="$existingLogo" :label="__('catalog_admin.logo')" />
                <div class="space-y-5">
                    <x-admin.field :label="__('catalog_admin.name_en')" for="form.name_en" required><input id="form.name_en" wire:model.blur="form.name_en" class="input" dir="ltr"></x-admin.field>
                    <x-admin.field :label="__('catalog_admin.name_ar')" for="form.name_ar" required><input id="form.name_ar" wire:model="form.name_ar" class="input" dir="rtl" lang="ar"></x-admin.field>
                </div>
            </div>
            <div class="grid gap-5 sm:grid-cols-3">
                <x-admin.field :label="__('catalog_admin.slug')" for="form.slug" required class="sm:col-span-2"><input id="form.slug" wire:model="form.slug" class="input font-mono" dir="ltr"></x-admin.field>
                <x-admin.field :label="__('catalog_admin.sort_order')" for="form.sort_order"><input id="form.sort_order" type="number" min="0" wire:model="form.sort_order" class="input tabular-nums"></x-admin.field>
            </div>
            <div class="grid gap-5 sm:grid-cols-2">
                <x-admin.field :label="__('catalog_admin.description_en')" for="form.description_en"><textarea id="form.description_en" wire:model="form.description_en" rows="3" class="input" dir="ltr"></textarea></x-admin.field>
                <x-admin.field :label="__('catalog_admin.description_ar')" for="form.description_ar"><textarea id="form.description_ar" wire:model="form.description_ar" rows="3" class="input" dir="rtl" lang="ar"></textarea></x-admin.field>
            </div>
            <x-admin.field :label="__('admin.status')" for="form.status">
                <select id="form.status" wire:model="form.status" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:w-48"><option value="active">{{ __('status.active') }}</option><option value="inactive">{{ __('status.inactive') }}</option></select>
            </x-admin.field>
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary" @click="open = false">{{ __('admin.cancel') }}</button>
                <button class="btn-primary" wire:loading.attr="disabled" wire:target="save,logo"><x-icon name="check" class="size-4" />{{ __('admin.save') }}</button>
            </div>
        </form>
    </x-admin.dialog>
</div>
