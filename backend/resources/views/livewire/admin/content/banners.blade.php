@php use App\Livewire\Admin\Content\BannerIndex; $now = now(); @endphp
<div>
    <x-admin.page-header :title="__('nav.banners')" :eyebrow="__('nav.content')" :description="__('content_admin.banners_hint')">
        <x-slot:actions>@if ($canCreate)<button type="button" wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('content_admin.new_banner') }}</button>@endif</x-slot:actions>
    </x-admin.page-header>

    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('content_admin.banners_search')" />
            <select wire:model.live="filterPlacement" class="input sm:w-52" aria-label="{{ __('content_admin.placement') }}">
                <option value="">{{ __('content_admin.all_placements') }}</option>
                @foreach (BannerIndex::PLACEMENTS as $p)<option value="{{ $p }}">{{ __('content_admin.placement_'.$p) }}</option>@endforeach
            </select>
            <x-slot:meta>{{ trans_choice('content_admin.banners_count', $banners->total(), ['count' => $banners->total()]) }}</x-slot:meta>
        </x-admin.toolbar>
        @if ($banners->isEmpty())
            <x-admin.empty :title="__('content_admin.banners_empty')" :description="__('content_admin.banners_empty_hint')" icon="image" />
        @else
            <ul class="grid gap-4 p-4 sm:grid-cols-2 sm:p-6 xl:grid-cols-3 [&>*]:min-w-0">
                @foreach ($banners as $b)
                    @php $live = $b->status === 'published' && (! $b->starts_at || $b->starts_at->lte($now)) && (! $b->ends_at || $b->ends_at->gt($now)); @endphp
                    <li class="overflow-hidden rounded-2xl border border-sand-200/70 dark:border-ink-700" wire:key="bn-{{ $b->id }}">
                        <div class="relative aspect-[16/7] bg-sand-100 dark:bg-ink-800">
                            <img src="{{ BannerIndex::mediaUrl(app()->getLocale() === 'ar' ? ($b->image_ar_path ?: $b->image_en_path) : $b->image_en_path) }}" alt="" class="size-full object-cover" loading="lazy">
                            <span class="absolute top-2 start-2">@if ($live)<span class="badge-success bg-white/90">{{ __('content_admin.visible') }}</span>@elseif ($b->status === 'published')<span class="badge-warning bg-white/90">{{ __('status.scheduled') }}</span>@else<span class="badge-neutral">{{ __('status.draft') }}</span>@endif</span>
                        </div>
                        <div class="p-4">
                            <p class="truncate font-medium text-ink-900 dark:text-ivory">{{ (app()->getLocale() === 'ar' ? ($b->title_ar ?: $b->title_en) : $b->title_en) ?: __('content_admin.untitled') }}</p>
                            <p class="mt-0.5 truncate text-xs text-ink-500">{{ __('content_admin.placement_'.$b->placement) }} · {{ __('content_admin.platform_'.$b->platform) }} · #{{ $b->sort_order }}@if ($b->link_type) · {{ __('content_admin.link_'.$b->link_type) }}@endif</p>
                            @if ($b->starts_at || $b->ends_at)<p class="mt-0.5 text-xs text-ink-500 tabular-nums"><x-icon name="clock" class="me-0.5 inline size-3" />{{ $b->starts_at ? \App\Support\BusinessTime::display($b->starts_at, 'd M') : '…' }} – {{ $b->ends_at ? \App\Support\BusinessTime::display($b->ends_at, 'd M Y') : '∞' }}</p>@endif
                            <div class="mt-3 flex items-center gap-1">
                                @if ($canEdit)
                                    <button type="button" wire:click="toggle('{{ $b->id }}')" class="btn-ghost btn-sm">{{ $b->status === 'published' ? __('content_admin.hide') : __('content_admin.publish') }}</button>
                                    <button type="button" wire:click="edit('{{ $b->id }}')" class="btn-ghost btn-sm ms-auto px-2" aria-label="{{ __('admin.edit') }}"><x-icon name="edit" class="size-4" /></button>
                                @endif
                                @if ($canDelete)<button type="button" wire:click="delete('{{ $b->id }}')" wire:confirm="{{ __('admin.confirm_delete') }}" class="btn-ghost btn-sm px-2 text-ruby-soft" aria-label="{{ __('admin.delete') }}"><x-icon name="trash" class="size-4" /></button>@endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
            @if ($banners->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $banners->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>

    <x-admin.dialog wire:model="showEditor" :title="$editingId ? __('content_admin.edit_banner') : __('content_admin.new_banner')" max-width="max-w-3xl">
        <form wire:submit="save" class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                <x-admin.image-input model="imageEn" :file="$imageEn" :current="BannerIndex::mediaUrl($current?->image_en_path)" :label="__('content_admin.image_en')" aspect="aspect-[16/7]" :hint="__('content_admin.image_hint')" />
                <x-admin.image-input model="imageAr" :file="$imageAr" :current="BannerIndex::mediaUrl($current?->image_ar_path)" :label="__('content_admin.image_ar')" aspect="aspect-[16/7]" :hint="__('content_admin.image_ar_hint')" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                <x-admin.field :label="__('content_admin.title_en')" for="form.title_en"><input id="form.title_en" wire:model="form.title_en" class="input" dir="ltr"></x-admin.field>
                <x-admin.field :label="__('content_admin.title_ar')" for="form.title_ar"><input id="form.title_ar" wire:model="form.title_ar" class="input" dir="rtl"></x-admin.field>
                <x-admin.field :label="__('content_admin.subtitle_en')" for="form.subtitle_en"><input id="form.subtitle_en" wire:model="form.subtitle_en" class="input" dir="ltr"></x-admin.field>
                <x-admin.field :label="__('content_admin.subtitle_ar')" for="form.subtitle_ar"><input id="form.subtitle_ar" wire:model="form.subtitle_ar" class="input" dir="rtl"></x-admin.field>
            </div>
            <div class="grid gap-4 sm:grid-cols-3 [&>*]:min-w-0">
                <x-admin.field :label="__('content_admin.placement')" for="form.placement"><select id="form.placement" wire:model="form.placement" class="input">@foreach (BannerIndex::PLACEMENTS as $p)<option value="{{ $p }}">{{ __('content_admin.placement_'.$p) }}</option>@endforeach</select></x-admin.field>
                <x-admin.field :label="__('content_admin.platform')" for="form.platform"><select id="form.platform" wire:model="form.platform" class="input">@foreach (['all', 'mobile', 'web'] as $p)<option value="{{ $p }}">{{ __('content_admin.platform_'.$p) }}</option>@endforeach</select></x-admin.field>
                <x-admin.field :label="__('content_admin.sort_order')" for="form.sort_order"><input id="form.sort_order" type="number" min="0" wire:model="form.sort_order" class="input tabular-nums"></x-admin.field>
            </div>
            <div class="grid gap-4 sm:grid-cols-3 [&>*]:min-w-0">
                <x-admin.field :label="__('content_admin.link_type')" for="form.link_type"><select id="form.link_type" wire:model.live="form.link_type" class="input"><option value="">{{ __('content_admin.no_link') }}</option>@foreach (BannerIndex::LINKS as $l)<option value="{{ $l }}">{{ __('content_admin.link_'.$l) }}</option>@endforeach</select></x-admin.field>
                @if ($form['link_type'] ?? '')
                    <x-admin.field :label="__('content_admin.link_value')" for="form.link_value" class="sm:col-span-2" :hint="__('content_admin.link_value_hint')"><input id="form.link_value" wire:model="form.link_value" class="input font-mono text-xs" dir="ltr"></x-admin.field>
                @endif
            </div>
            <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                <x-admin.field :label="__('content_admin.starts_at')" for="form.starts_at"><input id="form.starts_at" type="datetime-local" wire:model="form.starts_at" class="input"></x-admin.field>
                <x-admin.field :label="__('content_admin.ends_at')" for="form.ends_at"><input id="form.ends_at" type="datetime-local" wire:model="form.ends_at" class="input"></x-admin.field>
            </div>
            <x-admin.field :label="__('admin.status')" for="form.status"><select id="form.status" wire:model="form.status" class="input sm:w-48"><option value="draft">{{ __('status.draft') }}</option><option value="published">{{ __('status.published') }}</option></select></x-admin.field>
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary" @click="requestClose()">{{ __('admin.cancel') }}</button>
                <button class="btn-primary" wire:loading.attr="disabled" wire:target="save,imageEn,imageAr"><x-icon name="check" class="size-4" />{{ __('admin.save') }}</button>
            </div>
        </form>
    </x-admin.dialog>
</div>
