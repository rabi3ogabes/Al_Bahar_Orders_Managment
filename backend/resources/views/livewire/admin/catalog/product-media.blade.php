<div class="space-y-6">
    @if ($canEdit)
        <div class="card card-pad">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center">
                <label x-data="{ drag: false, uploading: false, progress: 0 }" @dragover.prevent="drag = true" @dragleave.prevent="drag = false" @drop="drag = false"
                       x-on:livewire-upload-start="uploading = true" x-on:livewire-upload-finish="uploading = false" x-on:livewire-upload-error="uploading = false" x-on:livewire-upload-progress="progress = $event.detail.progress"
                       class="relative flex flex-1 cursor-pointer items-center gap-4 rounded-2xl border-2 border-dashed px-5 py-5 transition"
                       :class="drag ? 'border-brass-400 bg-brass-50/60 dark:bg-brass-500/10' : 'border-sand-300 bg-sand-50/50 hover:border-brass-300 dark:border-ink-600 dark:bg-ink-800/30'">
                    <span class="grid size-12 shrink-0 place-items-center rounded-2xl bg-white text-brass-600 shadow-sm dark:bg-ink-800 dark:text-brass-300"><x-icon name="upload" class="size-5" /></span>
                    <span class="min-w-0">
                        <span class="block text-sm font-medium">{{ __('catalog_admin.drop_images') }}</span>
                        <span class="block text-xs text-ink-500">{{ __('catalog_admin.image_rules', ['mb' => round($maxKb / 1024)]) }}</span>
                    </span>
                    <span x-show="uploading" x-cloak class="ms-auto text-xs font-medium text-brass-600 tabular-nums" x-text="progress + '%'"></span>
                    <span wire:loading wire:target="uploads" class="ms-auto"><x-icon name="refresh" class="size-4 animate-spin text-brass-500" /></span>
                    <input type="file" multiple wire:model="uploads" accept="image/jpeg,image/png,image/webp" class="sr-only">
                </label>
                @if ($variants->count() > 1)
                    <x-admin.field :label="__('catalog_admin.link_to_variant')" for="uploadVariant" class="lg:w-64">
                        <select id="uploadVariant" wire:model="uploadVariant" class="input pe-10 rtl:[background-position:left_0.5rem_center]"><option value="">{{ __('catalog_admin.all_variants') }}</option>@foreach ($variants as $v)<option value="{{ $v->id }}">{{ $v->sku }}</option>@endforeach</select>
                    </x-admin.field>
                @endif
            </div>
            @error('uploads.*')<p class="field-error mt-2">{{ $message }}</p>@enderror
            @error('uploads')<p class="field-error mt-2">{{ $message }}</p>@enderror
        </div>
    @endif

    @if ($images->isEmpty())
        <div class="card"><x-admin.empty :title="__('catalog_admin.no_images')" :description="__('catalog_admin.no_images_hint')" icon="image" /></div>
    @else
        <p class="flex items-center gap-2 text-xs text-ink-500"><x-icon name="info" class="size-3.5" />{{ __('catalog_admin.drag_to_sort') }}</p>
        <ul class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" @if ($canEdit) wire:sort="sortImage" @endif>
            @foreach ($images as $img)
                <li wire:key="img-{{ $img->id }}" wire:sort:item="{{ $img->id }}" class="card overflow-hidden">
                    <div class="relative aspect-[4/3] bg-sand-100 dark:bg-ink-800">
                        <img src="{{ $img->url(640) }}" alt="{{ $img->alt() }}" class="size-full object-cover" loading="lazy">
                        @if ($loop->first)<span class="badge-brass absolute top-3 start-3 shadow">{{ __('catalog_admin.cover_image') }}</span>@endif
                        @if ($canEdit)
                            <span wire:sort:handle class="absolute top-3 end-3 grid size-8 cursor-grab place-items-center rounded-lg bg-white/90 text-ink-700 shadow active:cursor-grabbing dark:bg-ink-900/90 dark:text-sand-100" title="{{ __('catalog_admin.drag_to_sort') }}" aria-label="{{ __('catalog_admin.drag_to_sort') }}"><x-icon name="menu" class="size-4" /></span>
                        @endif
                        <span class="absolute bottom-3 end-3 rounded-md bg-ink-950/60 px-1.5 py-0.5 font-mono text-[0.65rem] text-ivory tabular-nums" dir="ltr">{{ $img->width }}×{{ $img->height }}</span>
                    </div>
                    <form wire:submit="saveMeta('{{ $img->id }}')" class="space-y-3 p-4" wire:sort:ignore>
                        <fieldset class="space-y-3" @disabled(! $canEdit)>
                            <input wire:model="meta.{{ $img->id }}.alt_en" class="input py-2 text-xs" dir="ltr" placeholder="{{ __('catalog_admin.alt_en') }}" aria-label="{{ __('catalog_admin.alt_en') }}">
                            <input wire:model="meta.{{ $img->id }}.alt_ar" class="input py-2 text-xs" dir="rtl" lang="ar" placeholder="{{ __('catalog_admin.alt_ar') }}" aria-label="{{ __('catalog_admin.alt_ar') }}">
                            @if ($variants->count() > 1)
                                <select wire:model="meta.{{ $img->id }}.product_variant_id" class="input pe-10 rtl:[background-position:left_0.5rem_center] py-2 text-xs" aria-label="{{ __('catalog_admin.link_to_variant') }}"><option value="">{{ __('catalog_admin.all_variants') }}</option>@foreach ($variants as $v)<option value="{{ $v->id }}">{{ $v->sku }}</option>@endforeach</select>
                            @endif
                        </fieldset>
                        @if ($canEdit)
                            <div class="flex items-center justify-between">
                                <button type="button" wire:click="delete('{{ $img->id }}')" wire:confirm="{{ __('catalog_admin.confirm_delete_image') }}" class="btn-ghost btn-sm px-2 text-ruby-soft" aria-label="{{ __('admin.delete') }}"><x-icon name="trash" class="size-4" /></button>
                                <button class="btn-secondary btn-sm" wire:loading.attr="disabled" wire:target="saveMeta('{{ $img->id }}')"><x-icon name="check" class="size-3.5" />{{ __('admin.save') }}</button>
                            </div>
                        @endif
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
</div>
