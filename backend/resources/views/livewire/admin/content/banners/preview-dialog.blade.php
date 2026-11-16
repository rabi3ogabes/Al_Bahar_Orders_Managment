<x-admin.dialog wire:model="showPreview" :title="__('banners_admin.preview_title')" max-width="max-w-3xl">
    @if ($preview)
        @php $set = $preview['settings']; $slides = $preview['slides']; @endphp
        <div wire:key="preview-{{ $previewId }}"
             x-data="{
                 slides: @js($slides), i: 0, lang: '{{ app()->getLocale() === 'ar' ? 'ar' : 'en' }}', dark: false, width: 'phone', paused: false, timer: null,
                 autoplay: {{ $set['autoplay'] ? 'true' : 'false' }}, every: {{ (int) $set['interval'] }} * 1000,
                 get count() { return this.slides.length; },
                 go(n) { this.i = (n + this.count) % this.count; this.restart(); },
                 restart() { clearInterval(this.timer); if (this.autoplay && this.count > 1 && ! window.matchMedia('(prefers-reduced-motion: reduce)').matches) { this.timer = setInterval(() => { if (! this.paused) { this.i = (this.i + 1) % this.count; } }, this.every); } },
                 init() { this.restart(); },
                 destroy() { clearInterval(this.timer); },
             }" @keydown.left.prevent="go(i + (lang === 'ar' ? 1 : -1))" @keydown.right.prevent="go(i + (lang === 'ar' ? -1 : 1))" data-no-dirty>
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-ink-500">{{ $preview['name'] }} · {{ $set['aspect'] }} · {{ $set['autoplay'] ? __('banners_admin.autoplay_every', ['n' => $set['interval']]) : __('banners_admin.autoplay_off') }}</p>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex rounded-lg border border-sand-200 p-0.5 text-xs dark:border-ink-700" role="group" aria-label="{{ __('banners_admin.preview_language') }}">
                        <button type="button" class="min-h-8 rounded px-2.5" :class="lang === 'en' ? 'bg-ink-900 text-ivory dark:bg-brass-500 dark:text-ink-950' : ''" :aria-pressed="(lang === 'en').toString()" @click="lang = 'en'">EN</button>
                        <button type="button" class="min-h-8 rounded px-2.5" :class="lang === 'ar' ? 'bg-ink-900 text-ivory dark:bg-brass-500 dark:text-ink-950' : ''" :aria-pressed="(lang === 'ar').toString()" @click="lang = 'ar'">ع</button>
                    </div>
                    <div class="flex rounded-lg border border-sand-200 p-0.5 text-xs dark:border-ink-700" role="group" aria-label="{{ __('banners_admin.preview_width') }}">
                        @foreach (['phone' => 'banners_admin.w_phone', 'tablet' => 'banners_admin.w_tablet', 'desktop' => 'banners_admin.w_desktop'] as $w => $key)
                            <button type="button" class="min-h-8 rounded px-2.5" :class="width === '{{ $w }}' ? 'bg-ink-900 text-ivory dark:bg-brass-500 dark:text-ink-950' : ''" :aria-pressed="(width === '{{ $w }}').toString()" @click="width = '{{ $w }}'">{{ __($key) }}</button>
                        @endforeach
                    </div>
                    <div class="flex rounded-lg border border-sand-200 p-0.5 dark:border-ink-700" role="group" aria-label="{{ __('banners_admin.preview_theme') }}">
                        <button type="button" class="grid min-h-8 min-w-8 place-items-center rounded" :class="! dark ? 'bg-ink-900 text-ivory dark:bg-brass-500 dark:text-ink-950' : ''" :aria-pressed="(! dark).toString()" aria-label="{{ __('banners_admin.theme_light') }}" @click="dark = false"><x-icon name="sun" class="size-3.5" /></button>
                        <button type="button" class="grid min-h-8 min-w-8 place-items-center rounded" :class="dark ? 'bg-ink-900 text-ivory dark:bg-brass-500 dark:text-ink-950' : ''" :aria-pressed="dark.toString()" aria-label="{{ __('banners_admin.theme_dark') }}" @click="dark = true"><x-icon name="moon" class="size-3.5" /></button>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-sand-200/70 p-4 transition-colors dark:border-ink-700" :class="dark ? 'bg-ink-950' : 'bg-ivory'" :dir="lang === 'ar' ? 'rtl' : 'ltr'">
                <div class="mx-auto transition-[max-width]" :class="{ 'max-w-[22rem]': width === 'phone', 'max-w-[34rem]': width === 'tablet', 'max-w-full': width === 'desktop' }"
                     role="region" aria-roledescription="carousel" aria-label="{{ $preview['name'] }}" @mouseenter="paused = true" @mouseleave="paused = false" @focusin="paused = true" @focusout="paused = false">
                    <div class="relative overflow-hidden bg-sand-100 dark:bg-ink-800 {{ $set['rounded'] ? 'rounded-2xl' : '' }}" style="aspect-ratio: {{ str_replace(':', ' / ', $set['aspect']) }}">
                        <template x-for="(s, n) in slides" :key="s.id">
                            <div class="absolute inset-0 transition-opacity duration-500 motion-reduce:transition-none" :class="n === i ? 'opacity-100' : 'pointer-events-none opacity-0'" role="group" aria-roledescription="slide" :aria-label="`${n + 1} / ${count}`" :aria-hidden="(n !== i).toString()">
                                <img :src="lang === 'ar' ? s.image_ar : s.image_en" alt="" class="size-full object-cover" :loading="n === 0 ? 'eager' : 'lazy'">
                                @if ($set['show_titles'])
                                    <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-ink-950/75 via-ink-950/35 to-transparent p-4 pt-10 text-ivory {{ $set['dots'] ? 'pb-7' : '' }}" x-show="(lang === 'ar' ? s.title_ar : s.title_en) || (lang === 'ar' ? s.subtitle_ar : s.subtitle_en)">
                                        <p class="font-display text-lg font-semibold leading-tight" x-text="lang === 'ar' ? s.title_ar : s.title_en"></p>
                                        <p class="mt-0.5 text-xs text-ivory/85" x-text="lang === 'ar' ? s.subtitle_ar : s.subtitle_en"></p>
                                    </div>
                                @endif
                                <span x-show="s.linked" class="absolute top-2 end-2 rounded-full bg-ink-950/60 p-1.5 text-ivory" title="{{ __('banners_admin.tappable') }}"><x-icon name="link" class="size-3.5" /></span>
                            </div>
                        </template>
                        <template x-if="count > 1">
                            <div class="contents">
                                <button type="button" class="absolute start-2 top-1/2 grid size-9 -translate-y-1/2 place-items-center rounded-full bg-white/85 text-ink-900 shadow transition hover:bg-white focus-visible:outline-2" aria-label="{{ __('banners_admin.prev') }}" @click="go(i - 1)"><x-icon name="chevron-left" class="size-4 rtl:rotate-180" /></button>
                                <button type="button" class="absolute end-2 top-1/2 grid size-9 -translate-y-1/2 place-items-center rounded-full bg-white/85 text-ink-900 shadow transition hover:bg-white focus-visible:outline-2" aria-label="{{ __('banners_admin.next') }}" @click="go(i + 1)"><x-icon name="chevron-right" class="size-4 rtl:rotate-180" /></button>
                            </div>
                        </template>
                        @if ($set['dots'])
                            <div class="absolute inset-x-0 bottom-2 flex justify-center gap-1.5" x-show="count > 1">
                                <template x-for="(s, n) in slides" :key="s.id"><button type="button" class="h-1.5 rounded-full bg-white transition-all" :class="n === i ? 'w-5' : 'w-1.5 opacity-60'" :aria-label="`${n + 1} / ${count}`" :aria-current="(n === i).toString()" @click="go(n)"></button></template>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
            <p class="mt-3 text-xs text-ink-500">{{ __('banners_admin.preview_note') }}@if ($preview['hidden'] > 0) {{ trans_choice('banners_admin.preview_hidden', $preview['hidden'], ['count' => $preview['hidden']]) }}@endif</p>
            @if (count($slides) === 0)<p class="mt-3 rounded-xl bg-amber-soft/10 px-4 py-3 text-sm text-amber-soft">{{ __('banners_admin.preview_none') }}</p>@endif
        </div>
    @endif
</x-admin.dialog>
