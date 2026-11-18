{{-- Settings → General & brand → "Intro screens": the title and text of the customer app's three intro slides, Arabic and English.
     The twelve fields are ordinary settings of this form (saved with "Save changes", cleaned and validated in SettingsIndex::save); an empty one means "use the
     app's default text", which is shown as the placeholder. The preview is pure Alpine reading the form's live values, so it follows every keystroke. It is drawn
     in cqw units, i.e. as a 360 dp phone scaled to the card, so the line breaks match what the customer sees. --}}
@use('App\Support\IntroSlides')
@php
    $limits = $intro['max'];
    $fieldNames = [];
    foreach ($intro['slides'] as $s) {
        foreach (IntroSlides::PARTS as $part) {
            foreach (IntroSlides::LANGS as $lang) { $fieldNames[$s['n']][$part][$lang] = IntroSlides::field($s['n'], $part, $lang); }
        }
    }
@endphp
<section class="border-t border-sand-100 dark:border-ink-800" aria-labelledby="intro-heading" data-intro-screens
         x-data="{
            theme: 'light',
            max: { title: {{ $limits['title'] }}, body: {{ $limits['body'] }} },
            val(f) { const x = this.$wire.values && this.$wire.values.general ? this.$wire.values.general[f] : ''; return typeof x === 'string' ? x : ''; },
            len(f) { return Array.from(this.val(f)).length; },
            plain(f) { return this.val(f).replace(/<\/?[a-z!][^>]*>/gi, '').replace(/\s+/g, ' ').trim(); },
            show(f, fallback) { const t = this.plain(f); return t === '' ? fallback : t; },
            custom(a, b) { return this.plain(a) !== '' || this.plain(b) !== ''; },
            tone(f, m) { const n = this.len(f); return n >= m ? 'text-ruby-soft font-semibold' : (n >= m * 0.9 ? 'text-amber-soft' : 'text-ink-400'); },
         }">
    <div class="flex flex-wrap items-start justify-between gap-4 px-5 pt-5 sm:px-6 sm:pt-6">
        <div class="min-w-0 max-w-2xl">
            <h3 id="intro-heading" class="font-display text-lg font-semibold">{{ __('intro_admin.section_title') }}</h3>
            <p class="mt-1 text-sm leading-relaxed text-ink-500">{{ __('intro_admin.section_hint') }}</p>
            @if ($canEdit)<p class="mt-1 text-xs text-ink-400">{{ __('intro_admin.save_note') }}</p>@endif
        </div>
        <div class="flex items-center gap-2" role="radiogroup" aria-label="{{ __('intro_admin.preview') }}">
            <span class="text-xs font-medium text-ink-500" aria-hidden="true">{{ __('intro_admin.preview') }}</span>
            <div class="inline-flex rounded-xl border border-sand-300 bg-sand-50 p-0.5 dark:border-ink-600 dark:bg-ink-800">
                @foreach (['light' => 'sun', 'dark' => 'moon'] as $mode => $icon)
                    <button type="button" role="radio" :aria-checked="theme === '{{ $mode }}' ? 'true' : 'false'" @click="theme = '{{ $mode }}'"
                            class="inline-flex min-h-11 items-center gap-1.5 rounded-[0.65rem] px-3 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2"
                            :class="theme === '{{ $mode }}' ? 'bg-white text-ink-900 shadow-sm dark:bg-ink-700 dark:text-ivory' : 'text-ink-500 hover:text-ink-900 dark:hover:text-ivory'">
                        <x-icon name="{{ $icon }}" class="size-4" />{{ __('intro_admin.theme_'.$mode) }}
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    <fieldset class="divide-y divide-sand-100 dark:divide-ink-800" @disabled(! $canEdit)>
        <legend class="sr-only">{{ __('intro_admin.section_title') }}</legend>
        @foreach ($intro['slides'] as $slide)
            @php $n = $slide['n']; $names = $fieldNames[$n]; @endphp
            <div class="px-5 py-6 sm:px-6" wire:key="intro-slide-{{ $n }}" data-intro-slide="{{ $n }}">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <h4 class="text-sm font-semibold text-ink-900 dark:text-sand-100">{{ __('intro_admin.slide', ['n' => $n]) }} · {{ __('intro_admin.slide_art.'.$n) }}</h4>
                        <p class="mt-0.5 text-xs text-ink-500">{{ __('intro_admin.slide_meaning.'.$n) }}</p>
                    </div>
                    @if ($canEdit)
                        <button type="button" class="btn-secondary btn-sm shrink-0" wire:click="resetIntroSlide({{ $n }})" wire:loading.attr="disabled" wire:target="resetIntroSlide({{ $n }})"
                                aria-label="{{ __('intro_admin.reset') }}: {{ __('intro_admin.slide', ['n' => $n]) }}">
                            <x-icon name="refresh" class="size-4" />{{ __('intro_admin.reset') }}
                        </button>
                    @endif
                </div>

                <div class="mt-5 grid gap-8 lg:grid-cols-2 lg:gap-10">
                    @foreach (IntroSlides::LANGS as $lang)
                        @php
                            $dir = $lang === 'ar' ? 'rtl' : 'ltr';
                            $tf = $names['title'][$lang]; $bf = $names['body'][$lang];
                            $tDefault = $slide['defaults']['title'][$lang]; $bDefault = $slide['defaults']['body'][$lang];
                        @endphp
                        <div class="min-w-0 space-y-4" wire:key="intro-{{ $n }}-{{ $lang }}">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="badge-neutral" lang="{{ $lang }}">{{ __('intro_admin.language.'.$lang) }}</span>
                                <span class="text-xs text-ink-500">{{ __('intro_admin.shown_in.'.$lang) }}</span>
                            </div>

                            <div>
                                <div class="flex items-baseline justify-between gap-3">
                                    <label for="{{ $tf }}" class="field-label !mb-1.5">{{ __('intro_admin.title_label') }}</label>
                                    <span class="text-xs tabular-nums" dir="ltr" :class="tone('{{ $tf }}', max.title)" x-text="len('{{ $tf }}') + ' / ' + max.title" aria-hidden="true"></span>
                                </div>
                                <input id="{{ $tf }}" type="text" wire:model="values.general.{{ $tf }}" dir="{{ $dir }}" lang="{{ $lang }}" maxlength="{{ $limits['title'] }}"
                                       placeholder="{{ $tDefault }}" autocomplete="off" enterkeyhint="done" class="input @error('values.general.'.$tf) input-error @enderror"
                                       aria-describedby="{{ $tf }}-count" @error('values.general.'.$tf) aria-invalid="true" @enderror>
                                <span id="{{ $tf }}-count" class="sr-only" x-text="len('{{ $tf }}') + ' / ' + max.title"></span>
                                @error('values.general.'.$tf)<p class="field-error" role="alert">{{ $message }}</p>@enderror
                            </div>

                            <div>
                                <div class="flex items-baseline justify-between gap-3">
                                    <label for="{{ $bf }}" class="field-label !mb-1.5">{{ __('intro_admin.body_label') }}</label>
                                    <span class="text-xs tabular-nums" dir="ltr" :class="tone('{{ $bf }}', max.body)" x-text="len('{{ $bf }}') + ' / ' + max.body" aria-hidden="true"></span>
                                </div>
                                <textarea id="{{ $bf }}" rows="3" wire:model="values.general.{{ $bf }}" dir="{{ $dir }}" lang="{{ $lang }}" maxlength="{{ $limits['body'] }}"
                                          placeholder="{{ $bDefault }}" @keydown.enter.prevent class="input resize-none leading-relaxed @error('values.general.'.$bf) input-error @enderror"
                                          aria-describedby="{{ $bf }}-count" @error('values.general.'.$bf) aria-invalid="true" @enderror></textarea>
                                <span id="{{ $bf }}-count" class="sr-only" x-text="len('{{ $bf }}') + ' / ' + max.body"></span>
                                @error('values.general.'.$bf)<p class="field-error" role="alert">{{ $message }}</p>@enderror
                            </div>

                            {{-- The slide's text card as the customer sees it (a 360 dp phone, scaled to the card). --}}
                            <figure class="m-0" aria-label="{{ __('intro_admin.preview') }}: {{ __('intro_admin.language.'.$lang) }}">
                                <div class="mx-auto w-full max-w-[20rem]" style="container-type: inline-size">
                                    <div class="rounded-2xl border text-center transition-colors" dir="{{ $dir }}" lang="{{ $lang }}" style="padding: 6.7cqw 6.7cqw 7.2cqw"
                                         :class="theme === 'dark' ? 'border-[#3A2E34] bg-[#181115]' : 'border-[#F0E4E8] bg-[#FCF9FA]'" data-intro-preview="{{ $n }}-{{ $lang }}">
                                        <div class="flex items-center justify-center" style="gap: 1.1cqw; margin-bottom: 5.5cqw" aria-hidden="true">
                                            @foreach ([1, 2, 3] as $dot)
                                                <span class="rounded-full" style="height: 2.2cqw; width: {{ $dot === $n ? '6.1cqw' : '2.2cqw' }}"
                                                      :class="theme === 'dark' ? '{{ $dot === $n ? 'bg-[#E58AA5]' : 'bg-[#3A2E34]' }}' : '{{ $dot === $n ? 'bg-[#B3254F]' : 'bg-[#F0E4E8]' }}'"></span>
                                            @endforeach
                                        </div>
                                        <p class="m-0 font-bold {{ $lang === 'ar' ? 'font-arabic' : 'font-sans' }}" style="font-size: 7.78cqw; line-height: 1.15; letter-spacing: {{ $lang === 'ar' ? '0' : '-0.04cqw' }}; overflow-wrap: anywhere"
                                           :class="theme === 'dark' ? 'text-[#F1ECE3]' : 'text-[#2A1A20]'" x-text="show('{{ $tf }}', @js($tDefault))" data-intro-preview-title>{{ $tDefault }}</p>
                                        <p class="m-0 {{ $lang === 'ar' ? 'font-arabic' : 'font-sans' }}" style="font-size: 4.44cqw; line-height: 1.5; margin-top: 2.2cqw; overflow-wrap: anywhere"
                                           :class="theme === 'dark' ? 'text-[#A6B0BE]' : 'text-[#736369]'" x-text="show('{{ $bf }}', @js($bDefault))" data-intro-preview-body>{{ $bDefault }}</p>
                                    </div>
                                </div>
                                <figcaption class="mt-2 text-center text-xs text-ink-500">
                                    <span x-show="! custom('{{ $tf }}', '{{ $bf }}')">{{ __('intro_admin.using_default') }}</span>
                                    <span x-show="custom('{{ $tf }}', '{{ $bf }}')" x-cloak>{{ __('intro_admin.using_custom') }}</span>
                                </figcaption>
                            </figure>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </fieldset>
</section>
