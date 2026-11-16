@php
    use App\Domain\Cms\Support\SliderSettings;
    $now = now();
    $hasSections = $blocks->isNotEmpty();
@endphp
<div x-data="{
        {{-- Instant feedback: the block moves under the finger at once; the server then confirms (and corrects, if somebody else moved things meanwhile). --}}
        swap(btn, dir) {
            const item = btn.closest('[data-move-item]');
            if (! item) { return; }
            const other = dir < 0 ? item.previousElementSibling : item.nextElementSibling;
            if (! other || ! other.hasAttribute('data-move-item')) { return; }
            dir < 0 ? item.parentNode.insertBefore(item, other) : item.parentNode.insertBefore(other, item);
        },
        {{-- Focus follows the block that moved, so a keyboard user can press the same arrow again. --}}
        refocus(id, dir) {
            this.$nextTick(() => {
                const same = document.querySelector(`[data-move='${id}:${dir}']`);
                (same && ! same.disabled ? same : document.querySelector(`[data-move='${id}:${-dir}']`))?.focus({ preventScroll: true });
            });
        },
     }">
    <x-admin.page-header :title="__('nav.banners')" :eyebrow="__('nav.content')" :description="__('banners_admin.page_hint')">
        <x-slot:actions>@if ($canCreate)<button type="button" wire:click="createSlider" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('banners_admin.add_slider') }}</button>@endif</x-slot:actions>
    </x-admin.page-header>

    <div wire:offline class="mb-4 flex items-center gap-2 rounded-xl border border-amber-soft/30 bg-amber-soft/10 px-4 py-2.5 text-sm text-amber-soft" role="status"><x-icon name="alert" class="size-4 shrink-0" />{{ __('banners_admin.offline') }}</div>

    @if (! $hasSections)
        <div class="card">
            <x-admin.empty :title="__('banners_admin.empty')" :description="__('banners_admin.empty_hint')" icon="image">
                @if ($canCreate)<button type="button" wire:click="createSlider" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('banners_admin.add_slider') }}</button>@endif
            </x-admin.empty>
        </div>
    @else
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-xs font-semibold uppercase tracking-wider text-ink-500">{{ __('banners_admin.map_title') }}</h2>
            <p class="text-xs text-ink-500">{{ __('banners_admin.map_hint') }}</p>
        </div>
        <ol class="space-y-3" aria-label="{{ __('banners_admin.map_title') }}" wire:loading.class="opacity-90">
            @foreach ($blocks as $b)
                @if ($b->section->isSlider())
                    @include('livewire.admin.content.banners.slider-card')
                @else
                    @include('livewire.admin.content.banners.section-row')
                @endif
            @endforeach
        </ol>
        @if (! $blocks->contains(fn ($b) => $b->section->isSlider()))
            <div class="mt-4 rounded-2xl border border-dashed border-brass-400/60 bg-brass-50/40 p-6 text-center dark:bg-brass-500/5">
                <p class="font-medium text-ink-900 dark:text-ivory">{{ __('banners_admin.no_slider_yet') }}</p>
                <p class="mx-auto mt-1 max-w-md text-sm text-ink-500">{{ __('banners_admin.no_slider_hint') }}</p>
                @if ($canCreate)<button type="button" wire:click="createSlider" class="btn-primary mt-4"><x-icon name="plus" class="size-4" />{{ __('banners_admin.add_slider') }}</button>@endif
            </div>
        @endif
    @endif

    {{-- Banners of the other places (category top, auctions) and old banners no slider shows. --}}
    @php $otherCount = $places->sum(fn ($c) => $c->count()) + $unassigned->count(); @endphp
    <section class="card mt-10 overflow-hidden" x-data="{ open: {{ $otherCount > 0 ? 'true' : 'false' }} }" aria-labelledby="other-places">
        <button type="button" class="flex w-full items-center gap-3 p-4 text-start sm:px-5" @click="open = ! open" :aria-expanded="open.toString()">
            <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-sand-100 text-ink-500 dark:bg-ink-800"><x-icon name="layers" class="size-5" /></span>
            <span class="min-w-0 flex-1"><span id="other-places" class="block font-display text-lg font-semibold text-ink-900 dark:text-ivory">{{ __('banners_admin.other_places') }}</span><span class="block text-xs text-ink-500">{{ __('banners_admin.other_places_hint') }}</span></span>
            <span class="badge-neutral">{{ $otherCount }}</span>
            <x-icon name="chevron-down" class="size-4 shrink-0 text-ink-400 transition" ::class="open ? 'rotate-180' : ''" />
        </button>
        <div x-show="open" x-collapse.duration.200ms class="divide-y divide-sand-100 border-t border-sand-100 dark:divide-ink-800 dark:border-ink-800">
            @foreach ($places as $placement => $items)
                <div>
                    <div class="flex flex-wrap items-center justify-between gap-2 bg-sand-50/60 px-4 py-2.5 sm:px-5 dark:bg-ink-800/30">
                        <h3 class="text-sm font-semibold text-ink-800 dark:text-sand-100">{{ __('content_admin.placement_'.$placement) }} <span class="font-normal text-ink-500">· {{ trans_choice('banners_admin.slides_count', $items->count(), ['count' => $items->count()]) }}</span></h3>
                        @if ($canCreate)<button type="button" wire:click="createSlide('place:{{ $placement }}')" class="btn-secondary btn-sm"><x-icon name="plus" class="size-3.5" />{{ __('banners_admin.add_banner') }}</button>@endif
                    </div>
                    @if ($items->isEmpty())
                        <p class="px-4 py-5 text-center text-sm text-ink-500 sm:px-5">{{ __('banners_admin.place_empty') }}</p>
                    @else
                        <ol class="divide-y divide-sand-100 dark:divide-ink-800" @if ($canEdit && $items->count() > 1) wire:sort="reorderSlide" @endif aria-label="{{ __('content_admin.placement_'.$placement) }}">
                            @foreach ($items as $row)
                                @include('livewire.admin.content.banners.slide-row', ['b' => $row, 'link' => $described[$row->id] ?? null, 'ratio' => '16 / 7', 'number' => $loop->iteration, 'first' => $loop->first, 'last' => $loop->last])
                            @endforeach
                        </ol>
                    @endif
                </div>
            @endforeach
            @if ($unassigned->isNotEmpty())
                <div>
                    <div class="bg-amber-soft/10 px-4 py-2.5 sm:px-5"><h3 class="flex items-center gap-2 text-sm font-semibold text-amber-soft"><x-icon name="alert" class="size-4" />{{ __('banners_admin.unassigned') }}</h3><p class="mt-0.5 text-xs text-ink-500">{{ __('banners_admin.unassigned_hint') }}</p></div>
                    <ol class="divide-y divide-sand-100 dark:divide-ink-800">
                        @foreach ($unassigned as $row)
                            @include('livewire.admin.content.banners.slide-row', ['b' => $row, 'link' => $described[$row->id] ?? null, 'ratio' => '16 / 7', 'number' => $loop->iteration, 'first' => true, 'last' => true])
                        @endforeach
                    </ol>
                </div>
            @endif
        </div>
    </section>

    {{-- The windows are only built for staff who may use them (a read-only user gets the map and the preview, nothing to change). --}}
    @if ($canCreate || $canEdit)
        @include('livewire.admin.content.banners.slider-dialog')
        @include('livewire.admin.content.banners.slide-dialog')
    @endif
    @include('livewire.admin.content.banners.preview-dialog')
    @if ($canDelete)
        <x-admin.dialog wire:model="showDeleteSlider" variant="modal" max-width="max-w-md" :title="__('banners_admin.delete_slider_q')">
            <p class="text-sm text-ink-600 dark:text-sand-200">{{ __('banners_admin.delete_slider_text') }}</p>
            <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn-secondary" data-cancel @click="open = false">{{ __('admin.cancel') }}</button>
                <button type="button" class="btn-danger" wire:click="deleteSlider" wire:loading.attr="disabled" wire:target="deleteSlider"><x-icon name="trash" class="size-4" />{{ __('banners_admin.delete_slider') }}</button>
            </div>
        </x-admin.dialog>
    @endif
</div>
