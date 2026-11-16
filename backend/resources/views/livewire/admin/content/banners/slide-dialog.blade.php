@php use App\Domain\Cms\Support\SliderSettings; @endphp
<x-admin.dialog wire:model="showSlide" :title="$slideId ? __('banners_admin.edit_slide_title') : __('banners_admin.add_slide')" max-width="max-w-3xl">
    @if ($editor)
        @php
            $css = str_replace(':', ' / ', $editor['aspect']);
            $enUrl = $imageEn && method_exists($imageEn, 'isPreviewable') && $imageEn->isPreviewable() ? $imageEn->temporaryUrl() : $editor['imageEn'];
            $arUrl = $imageAr && method_exists($imageAr, 'isPreviewable') && $imageAr->isPreviewable() ? $imageAr->temporaryUrl() : ($dropImageAr ? null : $editor['imageAr']);
            [$rw, $rh] = $editor['recommended'];
            $imageHint = __('banners_admin.image_hint', ['size' => $rw.' × '.$rh, 'mb' => round($editor['maxKb'] / 1024, 1)]);
            $set = $editor['settings'];
        @endphp
        <form wire:submit="saveSlide" class="space-y-6" wire:key="slide-form-{{ $slideId ?? 'new' }}-{{ $editorKey }}"
              x-data="{ lang: '{{ app()->getLocale() === 'ar' ? 'ar' : 'en' }}', dark: false,
                        en: { t: @js($slide['title_en'] ?? ''), s: @js($slide['subtitle_en'] ?? '') }, ar: { t: @js($slide['title_ar'] ?? ''), s: @js($slide['subtitle_ar'] ?? '') } }">

            {{-- Live preview: the slide as customers see it, in the slider's own shape, in English or Arabic (right to left), light or dark. --}}
            <section aria-label="{{ __('banners_admin.preview') }}">
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-ink-500">{{ __('banners_admin.preview') }}@if ($editor['sliderName']) <span class="font-normal normal-case tracking-normal">· {{ $editor['sliderName'] }} · {{ $editor['aspect'] }}</span>@endif</h3>
                    <div class="flex items-center gap-2" data-no-dirty>
                        <div class="flex rounded-lg border border-sand-200 p-0.5 text-xs dark:border-ink-700" role="group" aria-label="{{ __('banners_admin.preview_language') }}">
                            <button type="button" class="min-h-8 rounded px-2.5" :class="lang === 'en' ? 'bg-ink-900 text-ivory dark:bg-brass-500 dark:text-ink-950' : ''" :aria-pressed="(lang === 'en').toString()" @click="lang = 'en'">EN</button>
                            <button type="button" class="min-h-8 rounded px-2.5" :class="lang === 'ar' ? 'bg-ink-900 text-ivory dark:bg-brass-500 dark:text-ink-950' : ''" :aria-pressed="(lang === 'ar').toString()" @click="lang = 'ar'">ع</button>
                        </div>
                        <div class="flex rounded-lg border border-sand-200 p-0.5 dark:border-ink-700" role="group" aria-label="{{ __('banners_admin.preview_theme') }}">
                            <button type="button" class="grid min-h-8 min-w-8 place-items-center rounded" :class="! dark ? 'bg-ink-900 text-ivory dark:bg-brass-500 dark:text-ink-950' : ''" :aria-pressed="(! dark).toString()" aria-label="{{ __('banners_admin.theme_light') }}" title="{{ __('banners_admin.theme_light') }}" @click="dark = false"><x-icon name="sun" class="size-3.5" /></button>
                            <button type="button" class="grid min-h-8 min-w-8 place-items-center rounded" :class="dark ? 'bg-ink-900 text-ivory dark:bg-brass-500 dark:text-ink-950' : ''" :aria-pressed="dark.toString()" aria-label="{{ __('banners_admin.theme_dark') }}" title="{{ __('banners_admin.theme_dark') }}" @click="dark = true"><x-icon name="moon" class="size-3.5" /></button>
                        </div>
                    </div>
                </div>
                <div class="rounded-2xl border border-sand-200/70 p-3 transition-colors dark:border-ink-700" :class="dark ? 'bg-ink-950' : 'bg-ivory'" :dir="lang === 'ar' ? 'rtl' : 'ltr'">
                    <div class="relative overflow-hidden bg-sand-100 {{ $set['rounded'] || ! str_starts_with($slide['destination'] ?? '', 'slider:') ? 'rounded-2xl' : '' }}" style="aspect-ratio: {{ $css }}">
                        @if ($enUrl)
                            <img x-show="lang === 'en'" src="{{ $enUrl }}" alt="" class="absolute inset-0 size-full object-cover">
                            <img x-show="lang === 'ar'" x-cloak src="{{ $arUrl ?: $enUrl }}" alt="" class="absolute inset-0 size-full object-cover">
                        @else
                            <div class="absolute inset-0 grid place-items-center bg-[radial-gradient(120%_90%_at_30%_10%,var(--color-brass-100),var(--color-sand-100))] text-center text-ink-500 dark:bg-ink-800"><span class="px-6 text-xs"><x-icon name="image" class="mx-auto mb-1.5 size-6 text-ink-400" />{{ __('banners_admin.preview_empty') }}</span></div>
                        @endif
                        @if ($set['show_titles'])
                            <div x-show="(lang === 'ar' ? (ar.t || en.t) : en.t) || (lang === 'ar' ? (ar.s || en.s) : en.s)" x-cloak class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-ink-950/75 via-ink-950/35 to-transparent p-4 pt-10 text-ivory {{ $set['dots'] && str_starts_with($slide['destination'] ?? '', 'slider:') ? 'pb-7' : '' }}">
                                <p class="font-display text-lg font-semibold leading-tight sm:text-xl" x-text="lang === 'ar' ? (ar.t || en.t) : en.t"></p>
                                <p class="mt-0.5 text-xs text-ivory/85 sm:text-sm" x-text="lang === 'ar' ? (ar.s || en.s) : en.s"></p>
                            </div>
                        @endif
                        @if ($set['dots'] && str_starts_with($slide['destination'] ?? '', 'slider:'))
                            <div class="pointer-events-none absolute inset-x-0 bottom-1.5 flex justify-center gap-1.5" aria-hidden="true"><span class="h-1.5 w-4 rounded-full bg-white"></span><span class="size-1.5 rounded-full bg-white/60"></span><span class="size-1.5 rounded-full bg-white/60"></span></div>
                        @endif
                    </div>
                </div>
                <p class="field-hint" x-show="lang === 'ar' && {{ $arUrl ? 'false' : 'true' }}" x-cloak>{{ __('banners_admin.ar_fallback_note') }}</p>
            </section>

            <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                <div>
                    <x-admin.image-input model="imageEn" :file="$imageEn" :current="$editor['imageEn']" :label="__('content_admin.image_en')" :ratio="$css" :max-kb="$editor['maxKb']" :hint="$imageHint" />
                    @if ($editor['warn']['en'])<p class="field-hint flex items-start gap-1.5 text-amber-soft"><x-icon name="alert" class="mt-0.5 size-3.5 shrink-0" />{{ $editor['warn']['en'] }}</p>@endif
                </div>
                <div>
                    <x-admin.image-input model="imageAr" :file="$imageAr" :current="$dropImageAr ? null : $editor['imageAr']" :label="__('content_admin.image_ar')" :ratio="$css" :max-kb="$editor['maxKb']" :hint="__('banners_admin.image_ar_hint')" />
                    @if ($editor['warn']['ar'])<p class="field-hint flex items-start gap-1.5 text-amber-soft"><x-icon name="alert" class="mt-0.5 size-3.5 shrink-0" />{{ $editor['warn']['ar'] }}</p>@endif
                    @if (($editor['imageAr'] && ! $dropImageAr) && ! $imageAr)<button type="button" wire:click="$set('dropImageAr', true)" class="mt-1.5 text-xs font-medium text-ruby-soft hover:underline">{{ __('banners_admin.remove_ar_image') }}</button>@endif
                    @if ($dropImageAr)<p class="field-hint">{{ __('banners_admin.ar_will_be_removed') }} <button type="button" wire:click="$set('dropImageAr', false)" class="font-medium text-brass-700 hover:underline dark:text-brass-300">{{ __('admin.undo') }}</button></p>@endif
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                <x-admin.field :label="__('content_admin.title_en')" for="slide.title_en" :hint="$set['show_titles'] ? null : __('banners_admin.titles_hidden_hint')"><input id="slide.title_en" wire:model="slide.title_en" x-model="en.t" class="input" dir="ltr" maxlength="120"></x-admin.field>
                <x-admin.field :label="__('content_admin.title_ar')" for="slide.title_ar"><input id="slide.title_ar" wire:model="slide.title_ar" x-model="ar.t" class="input" dir="rtl" maxlength="120"></x-admin.field>
                <x-admin.field :label="__('content_admin.subtitle_en')" for="slide.subtitle_en"><input id="slide.subtitle_en" wire:model="slide.subtitle_en" x-model="en.s" class="input" dir="ltr" maxlength="200"></x-admin.field>
                <x-admin.field :label="__('content_admin.subtitle_ar')" for="slide.subtitle_ar"><input id="slide.subtitle_ar" wire:model="slide.subtitle_ar" x-model="ar.s" class="input" dir="rtl" maxlength="200"></x-admin.field>
            </div>

            @include('livewire.admin.content.banners.link-picker')

            <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                <x-admin.field :label="__('banners_admin.shows_in')" for="slide.destination" :hint="__('banners_admin.shows_in_hint')">
                    <select id="slide.destination" wire:model.live="slide.destination" class="input">
                        @if (($slide['destination'] ?? '') === '')<option value="">{{ __('banners_admin.choose_place') }}</option>@endif
                        @if ($sliderOptions)<optgroup label="{{ __('banners_admin.home_sliders') }}">@foreach ($sliderOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</optgroup>@endif
                        <optgroup label="{{ __('banners_admin.other_places') }}">@foreach (\App\Livewire\Admin\Content\BannerIndex::PLACEMENTS as $p)<option value="place:{{ $p }}">{{ __('content_admin.placement_'.$p) }}</option>@endforeach</optgroup>
                    </select>
                </x-admin.field>
                <x-admin.field :label="__('content_admin.platform')" for="slide.platform" :hint="__('banners_admin.platform_hint')"><select id="slide.platform" wire:model="slide.platform" class="input">@foreach (['all', 'mobile', 'web'] as $p)<option value="{{ $p }}">{{ __('content_admin.platform_'.$p) }}</option>@endforeach</select></x-admin.field>
            </div>

            <div class="grid gap-4 sm:grid-cols-3 [&>*]:min-w-0">
                <x-admin.field :label="__('admin.status')" for="slide.status"><select id="slide.status" wire:model="slide.status" class="input"><option value="published">{{ __('banners_admin.status_published') }}</option><option value="draft">{{ __('banners_admin.status_hidden') }}</option></select></x-admin.field>
                <x-admin.field :label="__('content_admin.starts_at')" for="slide.starts_at"><input id="slide.starts_at" type="datetime-local" wire:model="slide.starts_at" class="input"></x-admin.field>
                <x-admin.field :label="__('content_admin.ends_at')" for="slide.ends_at"><input id="slide.ends_at" type="datetime-local" wire:model="slide.ends_at" class="input"></x-admin.field>
            </div>

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn-secondary" @click="requestClose()">{{ __('admin.cancel') }}</button>
                <button class="btn-primary" wire:loading.attr="disabled" wire:target="saveSlide,imageEn,imageAr"><x-icon name="check" class="size-4" />{{ __('banners_admin.save_slide') }}</button>
            </div>
        </form>
    @endif
</x-admin.dialog>
