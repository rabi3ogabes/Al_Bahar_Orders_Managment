@php
    $icons = ['banners' => 'image', 'categories' => 'layers', 'featured_products' => 'star', 'new_arrivals' => 'sparkles', 'best_sellers' => 'chart', 'recommended' => 'gift',
        'product_collection' => 'sofa', 'brands' => 'crown', 'promotions' => 'percent', 'upcoming_auctions' => 'calendar', 'live_auctions' => 'gavel'];
    $now = now();
@endphp
<div>
    <x-admin.page-header :title="__('nav.homepage')" :eyebrow="__('nav.content')" :description="__('content_admin.homepage_hint')">
        <x-slot:actions>@if ($canCreate)<button type="button" wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('content_admin.add_section') }}</button>@endif</x-slot:actions>
    </x-admin.page-header>

    <div class="grid gap-6 xl:grid-cols-[1fr_24rem] [&>*]:min-w-0">
        <section class="card overflow-hidden">
            <div class="flex items-center justify-between gap-3 p-5 sm:px-6">
                <h2 class="font-display text-xl font-semibold">{{ __('content_admin.sections') }}</h2>
                @if ($canEdit && $sections->count() > 1)<span class="text-xs text-ink-500"><x-icon name="menu" class="me-1 inline size-3.5" />{{ __('content_admin.drag_hint') }}</span>@endif
            </div>
            @if ($sections->isEmpty())
                <x-admin.empty :title="__('content_admin.sections_empty')" :description="__('content_admin.sections_empty_hint')" icon="sparkles" class="border-t border-sand-100 dark:border-ink-800" />
            @else
                <ol class="divide-y divide-sand-100 border-t border-sand-100 dark:divide-ink-800 dark:border-ink-800" @if ($canEdit) wire:sort="reorder" @endif>
                    @foreach ($sections as $s)
                        @php
                            $live = $s->status === 'published' && (! $s->starts_at || $s->starts_at->lte($now)) && (! $s->ends_at || $s->ends_at->gt($now));
                            $scheduled = $s->status === 'published' && ! $live;
                        @endphp
                        <li class="flex items-center gap-3 bg-white px-4 py-3.5 sm:px-6 dark:bg-ink-900" wire:key="hs-{{ $s->id }}" @if ($canEdit) wire:sort:item="{{ $s->id }}" @endif>
                            @if ($canEdit)<span wire:sort:handle class="cursor-grab text-ink-300 hover:text-ink-500 active:cursor-grabbing" aria-hidden="true"><x-icon name="menu" class="size-4" /></span>@endif
                            <span class="w-6 text-center font-mono text-xs text-ink-400">{{ $loop->iteration }}</span>
                            <span class="grid size-9 shrink-0 place-items-center rounded-xl {{ $live ? 'bg-brass-50 text-brass-600 dark:bg-brass-500/15 dark:text-brass-300' : 'bg-sand-100 text-ink-400 dark:bg-ink-800' }}"><x-icon :name="$icons[$s->type] ?? 'layers'" class="size-4" /></span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium {{ $live ? 'text-ink-900 dark:text-ivory' : 'text-ink-500' }}">{{ (app()->getLocale() === 'ar' ? ($s->title_ar ?: $s->title_en) : $s->title_en) ?: __('content_admin.type_'.$s->type) }}</p>
                                <p class="truncate text-xs text-ink-500">{{ __('content_admin.type_'.$s->type) }} · {{ __('content_admin.layout_'.$s->layout) }}@if ($s->items_count) · {{ trans_choice('content_admin.n_items', $s->items_count, ['count' => $s->items_count]) }}@endif
                                    @if ($s->starts_at || $s->ends_at) · <x-icon name="clock" class="inline size-3" /> {{ $s->starts_at ? \App\Support\BusinessTime::display($s->starts_at, 'd M') : '…' }}–{{ $s->ends_at ? \App\Support\BusinessTime::display($s->ends_at, 'd M') : '∞' }}@endif</p>
                            </div>
                            @if ($live)<span class="badge-success">{{ __('content_admin.visible') }}</span>@elseif ($scheduled)<span class="badge-warning">{{ __('status.scheduled') }}</span>@else<span class="badge-neutral">{{ __('content_admin.hidden') }}</span>@endif
                            @if ($canEdit)
                                <div class="flex items-center">
                                    <button type="button" wire:click="move('{{ $s->id }}', -1)" @disabled($loop->first) class="btn-ghost btn-sm px-1.5" aria-label="{{ __('content_admin.move_up') }}"><x-icon name="chevron-down" class="size-4 rotate-180" /></button>
                                    <button type="button" wire:click="move('{{ $s->id }}', 1)" @disabled($loop->last) class="btn-ghost btn-sm px-1.5" aria-label="{{ __('content_admin.move_down') }}"><x-icon name="chevron-down" class="size-4" /></button>
                                    <button type="button" wire:click="toggle('{{ $s->id }}')" class="btn-ghost btn-sm px-1.5" aria-label="{{ $s->status === 'published' ? __('content_admin.hide') : __('content_admin.show') }}" title="{{ $s->status === 'published' ? __('content_admin.hide') : __('content_admin.show') }}"><x-icon name="eye" class="size-4 {{ $s->status === 'published' ? '' : 'opacity-40' }}" /></button>
                                    <button type="button" wire:click="edit('{{ $s->id }}')" class="btn-ghost btn-sm px-1.5" aria-label="{{ __('admin.edit') }}"><x-icon name="edit" class="size-4" /></button>
                                    @if ($canDelete)<button type="button" wire:click="delete('{{ $s->id }}')" wire:confirm="{{ __('admin.confirm_delete') }}" class="btn-ghost btn-sm px-1.5 text-ruby-soft" aria-label="{{ __('admin.delete') }}"><x-icon name="trash" class="size-4" /></button>@endif
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>

        <aside class="xl:sticky xl:top-24 xl:self-start">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-xs font-semibold uppercase tracking-wider text-ink-500">{{ __('content_admin.live_preview') }}</h2>
                <div class="flex rounded-lg border border-sand-200 p-0.5 text-xs dark:border-ink-700">
                    @foreach (['en' => 'EN', 'ar' => 'ع'] as $l => $label)<button type="button" wire:click="$set('previewLocale', '{{ $l }}')" class="rounded px-2 py-0.5 {{ $previewLocale === $l ? 'bg-ink-900 text-ivory dark:bg-brass-500 dark:text-ink-950' : '' }}">{{ $label }}</button>@endforeach
                </div>
            </div>
            <div class="mx-auto max-w-[22rem] rounded-[2.5rem] border-[6px] border-ink-900 bg-ivory p-3 shadow-[var(--shadow-lift)] dark:bg-ink-950" dir="{{ $previewLocale === 'ar' ? 'rtl' : 'ltr' }}">
                <div class="mx-auto mb-3 h-1.5 w-16 rounded-full bg-ink-900/80"></div>
                <div class="max-h-[36rem] space-y-4 overflow-y-auto px-1 pb-4 [scrollbar-width:none]" wire:loading.class="opacity-60">
                    @forelse ($preview as $ps)
                        <div wire:key="pv-{{ $ps['id'] }}">
                            @if ($ps['title'])<p class="mb-2 font-display text-base font-semibold text-ink-900 dark:text-ivory">{{ $ps['title'] }}</p>@endif
                            <div class="flex gap-2 overflow-x-hidden">
                                @foreach (array_slice($ps['items'], 0, $ps['layout'] === 'hero' ? 1 : 4) as $it)
                                    @php $img = $it['image_url'] ?? ($it['logo_url'] ?? ($it['images'][0]['url'] ?? ($it['image']['url'] ?? ($it['cover_image_url'] ?? null)))); $label = $it['name'] ?? ($it['title'] ?? ''); @endphp
                                    <div class="{{ $ps['layout'] === 'hero' ? 'w-full' : ($ps['layout'] === 'chips' ? 'shrink-0' : 'w-24 shrink-0') }}">
                                        @if ($ps['layout'] === 'chips')
                                            <span class="inline-block rounded-full border border-sand-200 bg-white px-3 py-1 text-[0.7rem] dark:border-ink-700 dark:bg-ink-900">{{ $label }}</span>
                                        @else
                                            <div class="{{ $ps['layout'] === 'hero' ? 'aspect-[16/9]' : 'aspect-square' }} overflow-hidden rounded-xl bg-sand-100 dark:bg-ink-800">@if ($img)<img src="{{ $img }}" alt="" class="size-full object-cover" loading="lazy">@endif</div>
                                            <p class="mt-1 line-clamp-2 text-[0.65rem] leading-tight text-ink-700 dark:text-sand-200">{{ $label }}</p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <p class="py-16 text-center text-xs text-ink-500">{{ __('content_admin.preview_empty') }}</p>
                    @endforelse
                </div>
            </div>
            <p class="mt-3 text-center text-xs text-ink-500">{{ __('content_admin.preview_hint') }}</p>
        </aside>
    </div>

    <x-admin.dialog wire:model="showEditor" :title="$editingId ? __('content_admin.edit_section') : __('content_admin.add_section')" max-width="max-w-2xl">
        <form wire:submit="save" class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-3 [&>*]:min-w-0">
                <x-admin.field :label="__('content_admin.section_type')" for="form.type" class="sm:col-span-2">
                    <select id="form.type" wire:model.live="form.type" class="input">@foreach (\App\Livewire\Admin\Content\HomepageIndex::TYPES as $t)<option value="{{ $t }}">{{ __('content_admin.type_'.$t) }}</option>@endforeach</select>
                </x-admin.field>
                <x-admin.field :label="__('content_admin.layout')" for="form.layout">
                    <select id="form.layout" wire:model="form.layout" class="input">@foreach (\App\Livewire\Admin\Content\HomepageIndex::LAYOUTS as $l)<option value="{{ $l }}">{{ __('content_admin.layout_'.$l) }}</option>@endforeach</select>
                </x-admin.field>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                <x-admin.field :label="__('content_admin.title_en')" for="form.title_en"><input id="form.title_en" wire:model="form.title_en" class="input" dir="ltr"></x-admin.field>
                <x-admin.field :label="__('content_admin.title_ar')" for="form.title_ar"><input id="form.title_ar" wire:model="form.title_ar" class="input" dir="rtl"></x-admin.field>
                <x-admin.field :label="__('content_admin.subtitle_en')" for="form.subtitle_en"><input id="form.subtitle_en" wire:model="form.subtitle_en" class="input" dir="ltr"></x-admin.field>
                <x-admin.field :label="__('content_admin.subtitle_ar')" for="form.subtitle_ar"><input id="form.subtitle_ar" wire:model="form.subtitle_ar" class="input" dir="rtl"></x-admin.field>
            </div>
            @if (($form['type'] ?? '') === 'banners')
                <x-admin.field :label="__('content_admin.placement')" for="form.placement" :hint="__('content_admin.banner_section_hint')">
                    <select id="form.placement" wire:model="form.placement" class="input">@foreach (['home_hero', 'home_strip', 'category_top', 'auctions'] as $pl)<option value="{{ $pl }}">{{ __('content_admin.placement_'.$pl) }}</option>@endforeach</select>
                </x-admin.field>
            @endif
            @if ($itemType)
                <div>
                    <span class="field-label">{{ __('content_admin.items') }}</span>
                    <div class="relative" x-data="{ f: false }" @click.outside="f = false">
                        <input type="search" wire:model.live.debounce.300ms="itemSearch" @focus="f = true" class="input" placeholder="{{ __('content_admin.search_'.$itemType) }}" aria-label="{{ __('content_admin.items') }}">
                        @if ($results->isNotEmpty())
                            <ul x-show="f" class="absolute inset-x-0 top-full z-30 mt-1 max-h-60 overflow-y-auto rounded-xl border border-sand-200 bg-white p-1 shadow-[var(--shadow-lift)] dark:border-ink-700 dark:bg-ink-900">
                                @foreach ($results as $rid => $rname)<li><button type="button" wire:click="addItem('{{ $rid }}')" class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-start text-sm hover:bg-sand-50 dark:hover:bg-ink-800">{{ $rname }}<x-icon name="plus" class="size-4 text-brass-500" /></button></li>@endforeach
                            </ul>
                        @endif
                    </div>
                    @if ($itemIds)
                        <ol class="mt-3 space-y-1.5">
                            @foreach ($itemIds as $i => $iid)
                                <li class="flex items-center gap-2 rounded-lg bg-sand-50 px-3 py-2 text-sm dark:bg-ink-800/60" wire:key="it-{{ $iid }}"><span class="font-mono text-xs text-ink-400">{{ $i + 1 }}</span><span class="min-w-0 flex-1 truncate">{{ $itemNames[$iid] ?? '—' }}</span><button type="button" wire:click="removeItem({{ $i }})" class="text-ink-400 hover:text-ruby-soft" aria-label="{{ __('org_admin.remove') }}"><x-icon name="x" class="size-4" /></button></li>
                            @endforeach
                        </ol>
                    @else
                        <p class="field-hint">{{ __('content_admin.items_auto_hint') }}</p>
                    @endif
                </div>
            @endif
            <div class="grid gap-4 sm:grid-cols-3 [&>*]:min-w-0">
                <x-admin.field :label="__('content_admin.item_limit')" for="form.item_limit"><input id="form.item_limit" type="number" min="1" max="50" wire:model="form.item_limit" class="input tabular-nums"></x-admin.field>
                <x-admin.field :label="__('content_admin.starts_at')" for="form.starts_at"><input id="form.starts_at" type="datetime-local" wire:model="form.starts_at" class="input"></x-admin.field>
                <x-admin.field :label="__('content_admin.ends_at')" for="form.ends_at"><input id="form.ends_at" type="datetime-local" wire:model="form.ends_at" class="input"></x-admin.field>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" @checked(($form['status'] ?? '') === 'published') wire:click="$set('form.status', '{{ ($form['status'] ?? '') === 'published' ? 'draft' : 'published' }}')">{{ __('content_admin.show_on_homepage') }}</label>
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary" @click="requestClose()">{{ __('admin.cancel') }}</button>
                <button class="btn-primary" wire:loading.attr="disabled" wire:target="save"><x-icon name="check" class="size-4" />{{ __('admin.save') }}</button>
            </div>
        </form>
    </x-admin.dialog>
</div>
