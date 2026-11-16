@php
    use App\Livewire\Admin\Content\BannerIndex;
    $ar = app()->getLocale() === 'ar';
    $title = trim((string) ($ar ? ($b->title_ar ?: $b->title_en) : ($b->title_en ?: $b->title_ar)));
    $name = $title !== '' ? $title : __('banners_admin.untitled_slide');
    $img = BannerIndex::mediaUrl($ar ? ($b->image_ar_path ?: $b->image_en_path) : $b->image_en_path);
    $live = $b->isLive();
    $scheduled = $b->status === 'published' && $b->starts_at && $b->starts_at->gt($now);
    $ended = $b->status === 'published' && $b->ends_at && $b->ends_at->lte($now);
    $btn = 'btn-ghost size-11 shrink-0 p-0 disabled:opacity-30';
@endphp
<li class="flex flex-wrap items-center gap-x-3 gap-y-2 bg-white p-3 sm:p-4 dark:bg-ink-900" data-move-item wire:key="slide-{{ $b->id }}" @if ($canEdit) wire:sort:item="{{ $b->id }}" @endif>
    @if ($canEdit)<span wire:sort:handle class="hidden cursor-grab touch-none text-ink-300 hover:text-ink-500 active:cursor-grabbing sm:block" title="{{ __('banners_admin.drag') }}" aria-hidden="true"><x-icon name="menu" class="size-4" /></span>@endif
    <div class="relative w-24 shrink-0 overflow-hidden rounded-xl bg-sand-100 ring-1 ring-sand-200/70 sm:w-44 dark:bg-ink-800 dark:ring-ink-700" style="aspect-ratio: {{ $ratio }}">
        @if ($img)<img src="{{ $img }}" alt="" class="size-full object-cover {{ $live ? '' : 'opacity-60' }}" loading="lazy" decoding="async">@else<span class="grid size-full place-items-center text-ink-400"><x-icon name="image" class="size-5" /></span>@endif
        <span class="absolute start-1.5 top-1.5 font-mono text-[0.65rem] leading-none"><span class="rounded bg-ink-950/70 px-1.5 py-1 text-ivory">{{ $number }}</span></span>
    </div>
    <div class="min-w-0 flex-1 basis-36">
        <p class="truncate text-sm font-medium text-ink-900 dark:text-ivory">{{ $name }}</p>
        @php $sub = trim((string) ($ar ? ($b->subtitle_ar ?: $b->subtitle_en) : ($b->subtitle_en ?: $b->subtitle_ar))); @endphp
        @if ($sub !== '')<p class="truncate text-xs text-ink-500">{{ $sub }}</p>@endif
        <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
            @if ($live)<span class="badge-success">{{ __('banners_admin.live') }}</span>
            @elseif ($scheduled)<span class="badge-warning"><x-icon name="clock" class="size-3" />{{ __('status.scheduled') }}</span>
            @elseif ($ended)<span class="badge-neutral">{{ __('banners_admin.ended') }}</span>
            @else<span class="badge-neutral">{{ __('content_admin.hidden') }}</span>@endif
            @if ($link === null)<span class="badge-neutral" title="{{ __('banners_admin.no_link_hint') }}"><x-icon name="ban" class="size-3" />{{ __('content_admin.no_link') }}</span>
            @elseif ($link['ok'])<span class="badge-brass max-w-[14rem]" title="{{ __('content_admin.link_'.$link['type']) }}: {{ $link['label'] }}"><x-icon :name="\App\Domain\Cms\Services\BannerLinks::ICONS[$link['type']] ?? 'link'" class="size-3 shrink-0" /><span class="truncate">{{ $link['label'] }}</span></span>
            @else<span class="badge-danger" title="{{ __('banners_admin.broken_hint') }}"><x-icon name="alert" class="size-3" />{{ __('banners_admin.broken_link') }}</span>@endif
            @if ($b->platform !== 'all')<span class="badge-neutral">{{ __('content_admin.platform_'.$b->platform) }}</span>@endif
            @unless ($b->image_ar_path)<span class="badge-neutral" title="{{ __('banners_admin.en_only_hint') }}">{{ __('banners_admin.en_only') }}</span>@endunless
            @if ($b->starts_at || $b->ends_at)<span class="inline-flex items-center gap-1 text-xs tabular-nums text-ink-500"><x-icon name="clock" class="size-3" />{{ $b->starts_at ? \App\Support\BusinessTime::display($b->starts_at, 'd M') : '…' }} – {{ $b->ends_at ? \App\Support\BusinessTime::display($b->ends_at, 'd M Y') : '∞' }}</span>@endif
        </div>
    </div>
    <div class="flex flex-wrap items-center ms-auto">
        @if ($canEdit)
            <button type="button" @disabled($first) data-move="{{ $b->id }}:-1" class="{{ $btn }}" aria-label="{{ __('banners_admin.move_slide_up', ['name' => $name]) }}" title="{{ __('content_admin.move_up') }}"
                    @click="swap($el, -1); $wire.moveSlide('{{ $b->id }}', -1).then(() => refocus('{{ $b->id }}', -1))"><x-icon name="chevron-down" class="size-4 rotate-180" /></button>
            <button type="button" @disabled($last) data-move="{{ $b->id }}:1" class="{{ $btn }}" aria-label="{{ __('banners_admin.move_slide_down', ['name' => $name]) }}" title="{{ __('content_admin.move_down') }}"
                    @click="swap($el, 1); $wire.moveSlide('{{ $b->id }}', 1).then(() => refocus('{{ $b->id }}', 1))"><x-icon name="chevron-down" class="size-4" /></button>
            <button type="button" wire:click="toggleSlide('{{ $b->id }}')" class="{{ $btn }}" role="switch" aria-checked="{{ $b->status === 'published' ? 'true' : 'false' }}"
                    aria-label="{{ __('banners_admin.slide_visible', ['name' => $name]) }}" title="{{ $b->status === 'published' ? __('content_admin.hide') : __('content_admin.publish') }}"><x-icon name="eye" class="size-4 {{ $b->status === 'published' ? 'text-emerald-soft' : 'opacity-40' }}" /></button>
            <button type="button" wire:click="editSlide('{{ $b->id }}')" class="{{ $btn }}" aria-label="{{ __('banners_admin.edit_slide', ['name' => $name]) }}" title="{{ __('admin.edit') }}"><x-icon name="edit" class="size-4" /></button>
        @endif
        @if ($canCreate)<button type="button" wire:click="duplicateSlide('{{ $b->id }}')" class="{{ $btn }}" aria-label="{{ __('banners_admin.duplicate_slide', ['name' => $name]) }}" title="{{ __('banners_admin.duplicate') }}"><x-icon name="copy" class="size-4" /></button>@endif
        @if ($canDelete)<button type="button" wire:click="deleteSlide('{{ $b->id }}')" class="{{ $btn }} text-ruby-soft" aria-label="{{ __('banners_admin.delete_slide', ['name' => $name]) }}" title="{{ __('admin.delete') }}"><x-icon name="trash" class="size-4" /></button>@endif
    </div>
</li>
