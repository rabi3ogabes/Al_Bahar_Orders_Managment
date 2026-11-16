@php
    use App\Domain\Cms\Support\SliderSettings;
    $s = $b->section;
    $id = $s->id;
    $set = $b->settings;
    $ratio = SliderSettings::cssRatio($set['aspect']);
    $visible = $s->status === 'published' && (! $s->starts_at || $s->starts_at->lte($now)) && (! $s->ends_at || $s->ends_at->gt($now));
    $scheduled = $s->status === 'published' && ! $visible;
    $showing = $visible && $b->live > 0;
    $btn = 'btn-ghost size-11 shrink-0 p-0 disabled:opacity-30';
    $count = $b->slides->count();
@endphp
<li data-move-item wire:key="blk-{{ $id }}" class="relative ps-11 sm:ps-14">
    <span class="absolute start-0 top-4 grid size-8 place-items-center rounded-full bg-brass-500 font-mono text-xs font-semibold text-ink-950 shadow-sm sm:size-9" aria-hidden="true">{{ $b->position }}</span>
    <article class="card overflow-hidden {{ $showing ? 'ring-1 ring-brass-400/40' : '' }}" x-data="{ open: (() => { try { return localStorage.getItem('slider-closed-{{ $id }}') !== '1'; } catch (e) { return true; } })() }" aria-label="{{ $b->name }}">
        <header class="flex flex-wrap items-center gap-x-3 gap-y-2 p-4 sm:px-5">
            <button type="button" class="flex min-w-0 flex-1 basis-56 items-center gap-3 text-start" @click="open = ! open; try { localStorage.setItem('slider-closed-{{ $id }}', open ? '0' : '1'); } catch (e) {}" :aria-expanded="open.toString()" aria-controls="slides-{{ $id }}">
                <x-icon name="chevron-right" class="size-4 shrink-0 text-ink-400 transition rtl:rotate-180" ::class="open ? 'rotate-90 rtl:-rotate-90' : ''" />
                <span class="grid size-10 shrink-0 place-items-center rounded-xl {{ $showing ? 'bg-brass-50 text-brass-600 dark:bg-brass-500/15 dark:text-brass-300' : 'bg-sand-100 text-ink-400 dark:bg-ink-800' }}"><x-icon name="image" class="size-5" /></span>
                <span class="min-w-0">
                    <span class="flex flex-wrap items-center gap-2">
                        <span class="truncate font-display text-lg font-semibold text-ink-900 dark:text-ivory">{{ $b->name }}</span>
                        @if ($showing)<span class="badge-success">{{ __('content_admin.visible') }}</span>
                        @elseif ($scheduled)<span class="badge-warning">{{ __('status.scheduled') }}</span>
                        @elseif ($visible)<span class="badge-warning" title="{{ __('banners_admin.no_live_hint') }}"><x-icon name="alert" class="size-3" />{{ __('banners_admin.no_live_slides') }}</span>
                        @else<span class="badge-neutral">{{ __('content_admin.hidden') }}</span>@endif
                    </span>
                    <span class="mt-0.5 block text-xs text-ink-500">
                        {{ trans_choice('banners_admin.slides_count', $count, ['count' => $count]) }} · {{ $set['aspect'] }} · {{ $set['autoplay'] ? __('banners_admin.autoplay_every', ['n' => $set['interval']]) : __('banners_admin.autoplay_off') }}
                        · @if ($b->position === 1){{ __('banners_admin.at_top') }}@elseif ($b->position === $b->total){{ __('banners_admin.at_bottom') }}@else{{ __('banners_admin.after_section', ['name' => $b->after]) }}@endif
                    </span>
                </span>
            </button>
            <div class="flex flex-wrap items-center ms-auto">
                @if ($canEdit)
                    <button type="button" @disabled($b->position === 1) data-move="{{ $id }}:-1" class="{{ $btn }}" aria-label="{{ __('banners_admin.move_slider_up', ['name' => $b->name]) }}" title="{{ __('banners_admin.move_slider_up_short') }}"
                            @click="swap($el, -1); $wire.moveSlider('{{ $id }}', -1).then(() => refocus('{{ $id }}', -1))"><x-icon name="chevron-down" class="size-4 rotate-180" /></button>
                    <button type="button" @disabled($b->position === $b->total) data-move="{{ $id }}:1" class="{{ $btn }}" aria-label="{{ __('banners_admin.move_slider_down', ['name' => $b->name]) }}" title="{{ __('banners_admin.move_slider_down_short') }}"
                            @click="swap($el, 1); $wire.moveSlider('{{ $id }}', 1).then(() => refocus('{{ $id }}', 1))"><x-icon name="chevron-down" class="size-4" /></button>
                    <span class="mx-1 hidden h-6 w-px bg-sand-200 sm:block dark:bg-ink-700" aria-hidden="true"></span>
                    <button type="button" wire:click="toggleSlider('{{ $id }}')" role="switch" aria-checked="{{ $s->status === 'published' ? 'true' : 'false' }}" class="{{ $btn }}"
                            aria-label="{{ __('banners_admin.slider_visible', ['name' => $b->name]) }}" title="{{ $s->status === 'published' ? __('content_admin.hide') : __('content_admin.publish') }}"><x-icon name="eye" class="size-4 {{ $s->status === 'published' ? 'text-emerald-soft' : 'opacity-40' }}" /></button>
                @endif
                <button type="button" wire:click="openPreview('{{ $id }}')" class="{{ $btn }}" aria-label="{{ __('banners_admin.preview_slider', ['name' => $b->name]) }}" title="{{ __('banners_admin.preview') }}" @disabled($count === 0)><x-icon name="play" class="size-4" /></button>
                @if ($canEdit)<button type="button" wire:click="editSlider('{{ $id }}')" class="{{ $btn }}" aria-label="{{ __('banners_admin.slider_settings_for', ['name' => $b->name]) }}" title="{{ __('banners_admin.slider_settings') }}"><x-icon name="settings" class="size-4" /></button>@endif
                @if ($canDelete)<button type="button" wire:click="askDeleteSlider('{{ $id }}')" class="{{ $btn }} text-ruby-soft" aria-label="{{ __('banners_admin.delete_slider_named', ['name' => $b->name]) }}" title="{{ __('admin.delete') }}"><x-icon name="trash" class="size-4" /></button>@endif
            </div>
        </header>
        <div id="slides-{{ $id }}" x-show="open" x-collapse.duration.200ms class="border-t border-sand-100 dark:border-ink-800">
            @if ($count === 0)
                <div class="flex flex-col items-center gap-3 px-6 py-10 text-center">
                    <span class="grid size-12 place-items-center rounded-2xl bg-sand-100 text-brass-600 dark:bg-ink-800 dark:text-brass-300"><x-icon name="image" class="size-6" /></span>
                    <p class="font-medium text-ink-900 dark:text-ivory">{{ __('banners_admin.no_slides') }}</p>
                    <p class="max-w-sm text-sm text-ink-500">{{ __('banners_admin.no_slides_hint') }}</p>
                    @if ($canCreate)<button type="button" wire:click="createSlide('slider:{{ $id }}')" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('banners_admin.add_first_slide') }}</button>@endif
                </div>
            @else
                <ol class="divide-y divide-sand-100 dark:divide-ink-800" @if ($canEdit && $count > 1) wire:sort="reorderSlide" @endif aria-label="{{ __('banners_admin.slides_of', ['name' => $b->name]) }}">
                    @foreach ($b->slides as $row)
                        @include('livewire.admin.content.banners.slide-row', ['b' => $row, 'link' => $described[$row->id] ?? null, 'ratio' => $ratio, 'number' => $loop->iteration, 'first' => $loop->first, 'last' => $loop->last])
                    @endforeach
                </ol>
                @if ($canCreate)
                    <div class="border-t border-sand-100 p-3 dark:border-ink-800 sm:p-4">
                        @if ($count >= $maxSlides)<p class="text-center text-xs text-ink-500">{{ __('banners_admin.too_many_slides', ['max' => $maxSlides]) }}</p>
                        @else<button type="button" wire:click="createSlide('slider:{{ $id }}')" class="flex w-full items-center justify-center gap-2 rounded-xl border border-dashed border-sand-300 py-3 text-sm font-medium text-ink-600 transition hover:border-brass-400 hover:bg-brass-50/50 hover:text-ink-900 dark:border-ink-600 dark:text-ink-300 dark:hover:bg-brass-500/10"><x-icon name="plus" class="size-4" />{{ __('banners_admin.add_slide') }}</button>@endif
                    </div>
                @endif
            @endif
        </div>
    </article>
</li>
