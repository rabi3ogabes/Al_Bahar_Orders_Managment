@php
    $skyline = asset('brand/qatar-skyline.svg');
    $maskStyle = "-webkit-mask: url('{$skyline}') center / 100% 100% no-repeat; mask: url('{$skyline}') center / 100% 100% no-repeat;";
    $radioCard = 'group relative block cursor-pointer rounded-2xl border border-sand-200 p-3 transition hover:border-brass-400 has-checked:border-brass-500 has-checked:bg-brass-500/5 has-checked:ring-1 has-checked:ring-brass-500 has-focus-visible:ring-2 has-focus-visible:ring-brass-500 dark:border-ink-700';
    $segment = 'flex min-h-11 items-center justify-center gap-2 rounded-lg px-4 text-sm font-medium text-ink-600 transition peer-checked:bg-white peer-checked:text-ink-900 peer-checked:shadow-sm peer-focus-visible:outline-2 peer-focus-visible:outline-brass-500 dark:text-ink-300 dark:peer-checked:bg-ink-700 dark:peer-checked:text-ivory';
    $track = 'inline-grid grid-cols-2 gap-1 rounded-xl bg-sand-100 p-1 dark:bg-ink-800';
    $swatch = 'size-11 shrink-0 cursor-pointer rounded-xl border border-sand-300 bg-transparent p-1 disabled:cursor-not-allowed dark:border-ink-600';
    $low = $ratio !== null && $ratio < $minRatio;
@endphp
<div>
    <x-admin.page-header :title="__('backdrop_admin.title')" :eyebrow="__('nav.appearance')" :description="__('backdrop_admin.hint')">
        <x-slot:actions>
            <a href="{{ route('admin.appearance.index') }}" class="btn-secondary min-h-11" wire:navigate><x-icon name="chevron-left" class="size-4 rtl:rotate-180" />{{ __('backdrop_admin.back') }}</a>
            @if ($canEdit)<button type="button" class="btn-secondary min-h-11" @click="$dispatch('open-modal', 'reset-backdrop')"><x-icon name="refresh" class="size-4" />{{ __('backdrop_admin.reset') }}</button>@endif
        </x-slot:actions>
    </x-admin.page-header>

    {{--
        One Alpine scope for the form and the preview. The radio choices (picture, fit) are read straight from the Livewire form, which a click updates
        at once; the slider and the two colour pickers keep a local copy while they are dragged (op, color, tint) and send it on release, so the preview
        follows the hand with no round trip. The x-effect copies a server-side change (Back to default, a new picture) back into the local copies.
    --}}
    <form wire:submit="save" class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_26rem] lg:items-start"
          x-data="{
              mode: 'light',
              op: {{ (int) ($form['opacity'] ?? 8) }},
              color: @js($form['color'] ?? ''),
              tint: @js($form['tint'] ?? ''),
              bg: @js($theme['background']), surface: @js($theme['surface']), primary: @js($theme['primary']), primaryDark: @js($theme['primary_dark']),
              saved: false,
              get pageColor() { return this.mode === 'dark' ? '#181115' : (this.color || this.bg) },
              get cardColor() { return this.mode === 'dark' ? '#221A1E' : this.surface },
              get skyColor() { return this.tint || (this.mode === 'dark' ? this.primaryDark : this.primary) },
          }"
          x-effect="op = Number($wire.form.opacity); color = $wire.form.color || ''; tint = $wire.form.tint || ''"
          x-on:backdrop-saved.window="saved = true; setTimeout(() => saved = false, 2000)">
        <div class="space-y-6 [&>*]:min-w-0">
            {{-- 1. Which picture: pick one of three, so radio cards --}}
            <section class="card p-4 sm:p-6" aria-labelledby="bd-style-h">
                <h2 id="bd-style-h" class="font-display text-lg font-semibold">{{ __('backdrop_admin.picture') }}</h2>
                <p class="mb-4 text-sm text-ink-500 dark:text-ink-400">{{ __('backdrop_admin.picture_hint') }}</p>
                <div class="grid gap-3 sm:grid-cols-3" role="radiogroup" aria-labelledby="bd-style-h">
                    @foreach ($styles as $s)
                        <label class="{{ $radioCard }}" wire:key="bd-style-{{ $s }}">
                            <input type="radio" name="bd-style" value="{{ $s }}" class="sr-only" wire:model.live="form.style" @disabled(! $canEdit)>
                            <div class="relative mb-3 flex h-20 items-center justify-center overflow-hidden rounded-xl bg-sand-50 dark:bg-ink-900" aria-hidden="true">
                                @if ($s === 'skyline')
                                    <div class="absolute bottom-0 left-1/2 aspect-[1600/340] w-[150%] -translate-x-1/2 opacity-60" style="background-color: {{ $theme['primary'] }}; {{ $maskStyle }}"></div>
                                @elseif ($s === 'image')
                                    @if ($previewImage)
                                        <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('{{ $previewImage }}')"></div>
                                    @else
                                        <x-icon name="image" class="size-6 text-ink-400" />
                                    @endif
                                @else
                                    <x-icon name="ban" class="size-6 text-ink-400" />
                                @endif
                            </div>
                            <p class="flex items-center justify-between gap-2 text-sm font-semibold">
                                {{ __('backdrop_admin.style_'.$s) }}
                                <x-icon name="check" class="hidden size-4 text-brass-600 group-has-checked:block" />
                            </p>
                            <p class="mt-0.5 text-xs text-ink-500 dark:text-ink-400">{{ __('backdrop_admin.style_'.$s.'_hint') }}</p>
                        </label>
                    @endforeach
                </div>
                @error('form.style')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            </section>

            {{-- 2. My picture: upload, replace, remove, fit (only while "My picture" is chosen) --}}
            <section class="card p-4 sm:p-6" aria-labelledby="bd-pic-h" x-cloak x-show="$wire.form.style === 'image'">
                <h2 id="bd-pic-h" class="font-display text-lg font-semibold">{{ __('backdrop_admin.my_picture') }}</h2>
                <p class="mb-4 text-sm text-ink-500 dark:text-ink-400">{{ __('backdrop_admin.my_picture_hint') }}</p>
                @if ($canEdit)
                    <x-admin.image-input model="upload" :file="$upload" :current="$storedUrl" :label="__('backdrop_admin.picture_label')" ratio="16 / 9" :max-kb="4096" class="max-w-md" />
                    @if ($upload)
                        <p class="mt-2 text-sm text-emerald-soft dark:text-emerald-300" role="status">{{ __('backdrop_admin.upload_pending') }}</p>
                    @elseif ($needsPicture)
                        <p class="mt-2 text-sm text-ruby-soft" role="status">{{ __('backdrop_admin.upload_need') }}</p>
                    @endif
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" class="btn-secondary min-h-11" @click="$el.closest('section').querySelector('input[type=file]').click()"><x-icon name="upload" class="size-4" />{{ __('backdrop_admin.upload_replace') }}</button>
                        @if ($upload)
                            <button type="button" class="btn-ghost min-h-11" wire:click="discardUpload"><x-icon name="x" class="size-4" />{{ __('backdrop_admin.upload_discard') }}</button>
                        @elseif ($storedUrl)
                            <button type="button" class="btn-ghost min-h-11 text-ruby-soft" @click="$dispatch('open-modal', 'remove-picture')"><x-icon name="trash" class="size-4" />{{ __('backdrop_admin.upload_remove') }}</button>
                        @endif
                    </div>
                @elseif ($storedUrl)
                    <div class="aspect-video max-w-md overflow-hidden rounded-xl border border-sand-200 bg-sand-50 bg-cover bg-center dark:border-ink-700" style="background-image: url('{{ $storedUrl }}')" role="img" aria-label="{{ __('backdrop_admin.my_picture') }}"></div>
                @endif

                <fieldset class="mt-6">
                    <legend class="field-label">{{ __('backdrop_admin.fit') }}</legend>
                    <div class="{{ $track }}" role="radiogroup">
                        @foreach ($fits as $f)
                            <label class="block" wire:key="bd-fit-{{ $f }}">
                                <input type="radio" name="bd-fit" value="{{ $f }}" class="peer sr-only" wire:model.live="form.fit" @disabled(! $canEdit)>
                                <span class="{{ $segment }}">{{ __('backdrop_admin.fit_'.$f) }}</span>
                            </label>
                        @endforeach
                    </div>
                    <p class="field-hint" x-cloak x-show="$wire.form.fit === 'bottom'">{{ __('backdrop_admin.fit_bottom_hint') }}</p>
                    <p class="field-hint" x-cloak x-show="$wire.form.fit === 'cover'">{{ __('backdrop_admin.fit_cover_hint') }}</p>
                </fieldset>
            </section>

            {{-- 3. Strength and colours --}}
            <section class="card p-4 sm:p-6" aria-labelledby="bd-look-h">
                <h2 id="bd-look-h" class="mb-4 font-display text-lg font-semibold">{{ __('backdrop_admin.colours') }}</h2>
                <div class="space-y-6">
                    <div x-show="$wire.form.style !== 'none'">
                        <div class="flex items-baseline justify-between gap-3">
                            <label for="bd-op" class="field-label mb-0">{{ __('backdrop_admin.strength') }}</label>
                            <output for="bd-op" class="font-mono text-sm font-semibold tabular-nums" x-text="op + ' %'" dir="ltr">{{ (int) ($form['opacity'] ?? 8) }} %</output>
                        </div>
                        <input id="bd-op" type="range" min="{{ $min }}" max="{{ $max }}" step="1" value="{{ (int) ($form['opacity'] ?? 8) }}" x-model.number="op"
                               @change="$wire.$set('form.opacity', op)" :aria-valuetext="op + ' %'" @disabled(! $canEdit)
                               class="mt-1 block h-11 w-full cursor-pointer accent-brass-600 disabled:cursor-not-allowed">
                        <div class="flex justify-between text-xs text-ink-500 dark:text-ink-400" aria-hidden="true"><span dir="ltr">{{ $min }} %</span><span dir="ltr">{{ $max }} %</span></div>
                        <p class="field-hint">{{ __('backdrop_admin.strength_hint') }}</p>
                        @error('form.opacity')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <span class="field-label" id="bd-color-l">{{ __('backdrop_admin.page_colour') }}</span>
                        <div class="flex flex-wrap items-center gap-3">
                            <input type="color" aria-labelledby="bd-color-l" class="{{ $swatch }}" :value="color || bg" value="{{ $form['color'] ?: $theme['background'] }}"
                                   @input="color = $event.target.value" @change="$wire.$set('form.color', color || null)" @disabled(! $canEdit)>
                            <span class="font-mono text-xs uppercase text-ink-500 dark:text-ink-400" dir="ltr" x-text="color || @js(__('backdrop_admin.theme_colour'))">{{ $form['color'] ?: __('backdrop_admin.theme_colour') }}</span>
                            @if ($canEdit)
                                <button type="button" class="btn-ghost btn-sm min-h-11" x-cloak x-show="color" @click="color = ''; $wire.$set('form.color', null)">{{ __('backdrop_admin.use_theme_colour') }}</button>
                            @endif
                        </div>
                        <p class="field-hint">{{ __('backdrop_admin.page_colour_hint') }}</p>
                        @if ($ratio !== null)
                            <p class="mt-1.5 text-xs font-medium {{ $low ? 'text-ruby-soft' : 'text-emerald-soft dark:text-emerald-300' }}" role="status">
                                {{ $low ? __('backdrop_admin.contrast_low', ['ratio' => number_format($ratio, 1), 'min' => $minRatio]) : __('backdrop_admin.contrast_ok', ['ratio' => number_format($ratio, 1)]) }}
                            </p>
                        @endif
                        @error('form.color')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>

                    <div x-cloak x-show="$wire.form.style === 'skyline'">
                        <span class="field-label" id="bd-tint-l">{{ __('backdrop_admin.skyline_colour') }}</span>
                        <div class="flex flex-wrap items-center gap-3">
                            <input type="color" aria-labelledby="bd-tint-l" class="{{ $swatch }}" :value="tint || primary" value="{{ $form['tint'] ?: $theme['primary'] }}"
                                   @input="tint = $event.target.value" @change="$wire.$set('form.tint', tint || null)" @disabled(! $canEdit)>
                            <span class="font-mono text-xs uppercase text-ink-500 dark:text-ink-400" dir="ltr" x-text="tint || @js(__('backdrop_admin.theme_colour'))">{{ $form['tint'] ?: __('backdrop_admin.theme_colour') }}</span>
                            @if ($canEdit)
                                <button type="button" class="btn-ghost btn-sm min-h-11" x-cloak x-show="tint" @click="tint = ''; $wire.$set('form.tint', null)">{{ __('backdrop_admin.use_theme_colour') }}</button>
                            @endif
                        </div>
                        <p class="field-hint">{{ __('backdrop_admin.skyline_colour_hint') }}</p>
                        @error('form.tint')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                </div>
            </section>

            {{-- Save bar: stays in view; Save is off until something changed --}}
            @if ($canEdit)
                <div class="sticky bottom-3 z-10 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-sand-200 bg-white/95 p-3 shadow-[var(--shadow-lift)] backdrop-blur dark:border-ink-700 dark:bg-ink-900/95">
                    <p class="min-w-0 flex-1 px-1 text-sm {{ $dirty ? 'font-medium text-ink-800 dark:text-sand-100' : 'text-ink-500 dark:text-ink-400' }}" role="status">{{ $dirty ? __('backdrop_admin.unsaved') : __('backdrop_admin.all_saved') }}</p>
                    <button type="submit" class="btn-primary min-h-11 min-w-36" @disabled(! $dirty || $blocked) wire:loading.attr="disabled" wire:target="save" aria-live="polite">
                        <span class="inline-flex items-center gap-2" wire:loading.remove wire:target="save">
                            <template x-if="saved"><x-icon name="check" class="size-4" /></template>
                            <span x-text="saved ? @js(__('backdrop_admin.saved')) : @js(__('backdrop_admin.save'))">{{ $justSaved && ! $dirty ? __('backdrop_admin.saved') : __('backdrop_admin.save') }}</span>
                        </span>
                        <span wire:loading wire:target="save">{{ __('backdrop_admin.saving') }}</span>
                    </button>
                </div>
            @else
                <p class="rounded-2xl border border-sand-200 bg-sand-50 px-4 py-3 text-sm text-ink-600 dark:border-ink-700 dark:bg-ink-900 dark:text-ink-300" role="note">{{ __('backdrop_admin.view_only') }}</p>
            @endif
        </div>

        {{-- Live preview: a phone and a web page, light or dark. Decorative, so hidden from screen readers (every control is above). --}}
        <aside class="order-first lg:sticky lg:top-6 lg:order-none" aria-labelledby="bd-pv-h">
            <h2 id="bd-pv-h" class="font-display text-lg font-semibold">{{ __('backdrop_admin.preview') }}</h2>
            <p class="mb-3 text-sm text-ink-500 dark:text-ink-400">{{ __('backdrop_admin.preview_hint') }}</p>
            <div class="{{ $track }} mb-4" role="radiogroup" aria-label="{{ __('backdrop_admin.preview_mode') }}">
                @foreach (['light' => 'sun', 'dark' => 'moon'] as $m => $icon)
                    <label class="block">
                        <input type="radio" name="bd-mode" value="{{ $m }}" class="peer sr-only" x-model="mode">
                        <span class="{{ $segment }}"><x-icon :name="$icon" class="size-4" />{{ __('backdrop_admin.preview_'.$m) }}</span>
                    </label>
                @endforeach
            </div>

            <div class="flex items-end justify-center gap-4" aria-hidden="true">
                {{-- Phone --}}
                <figure class="m-0 shrink-0 text-center">
                    <div class="relative mx-auto flex h-[17rem] w-[8.5rem] flex-col overflow-hidden rounded-[1.6rem] border-[3px] border-ink-900 shadow-[var(--shadow-card)] dark:border-ink-600"
                         style="background: {{ $form['color'] ?: $theme['background'] }}" :style="{ background: pageColor }">
                        <div class="px-3 pb-5 pt-3 text-start" style="background: {{ $theme['primary'] }}"><p class="text-[10px] font-bold text-white">Al Bahar</p></div>
                        <div class="relative min-h-0 flex-1">
                            <x-admin.backdrop-layers :image="$previewImage" sky="w-[195%]" />
                            <div class="relative -mt-3 space-y-2 px-2">
                                <div class="h-12 rounded-xl shadow-sm" style="background: {{ $theme['surface'] }}" :style="{ background: cardColor }"></div>
                                <div class="grid grid-cols-2 gap-2">
                                    <div class="h-9 rounded-xl shadow-sm" style="background: {{ $theme['surface'] }}" :style="{ background: cardColor }"></div>
                                    <div class="h-9 rounded-xl shadow-sm" style="background: {{ $theme['surface'] }}" :style="{ background: cardColor }"></div>
                                </div>
                            </div>
                        </div>
                        <div class="px-2 pb-2 pt-1">
                            <div class="flex h-7 items-center justify-around rounded-full shadow-sm" style="background: {{ $theme['surface'] }}" :style="{ background: cardColor }">
                                @foreach ([0, 1, 2, 3] as $dot)<i class="size-2 rounded-full {{ $dot === 0 ? '' : 'bg-ink-300/70' }}" @if ($dot === 0) style="background: {{ $theme['primary'] }}" @endif></i>@endforeach
                            </div>
                        </div>
                    </div>
                    <figcaption class="mt-2 text-xs text-ink-500 dark:text-ink-400">{{ __('backdrop_admin.preview_phone') }}</figcaption>
                </figure>

                {{-- Web store --}}
                <figure class="m-0 min-w-0 flex-1 text-center">
                    <div class="relative overflow-hidden rounded-xl border border-sand-300 shadow-[var(--shadow-card)] dark:border-ink-600"
                         style="background: {{ $form['color'] ?: $theme['background'] }}" :style="{ background: pageColor }">
                        <div class="flex items-center gap-1 bg-sand-100 px-2 py-1.5 dark:bg-ink-800">
                            <i class="size-1.5 rounded-full bg-ink-300"></i><i class="size-1.5 rounded-full bg-ink-300"></i><i class="size-1.5 rounded-full bg-ink-300"></i>
                            <span class="ms-2 h-2 flex-1 rounded-full bg-white dark:bg-ink-700"></span>
                        </div>
                        <div class="relative h-[10.5rem]">
                            <x-admin.backdrop-layers :image="$previewImage" sky="w-full" />
                            <div class="relative flex h-6 items-center px-3 text-start" style="background: {{ $theme['primary'] }}"><p class="text-[8px] font-bold text-white">Al Bahar</p></div>
                            <div class="relative grid grid-cols-3 gap-2 p-3">
                                @foreach ([0, 1, 2] as $card)<div class="h-14 rounded-lg shadow-sm" style="background: {{ $theme['surface'] }}" :style="{ background: cardColor }"></div>@endforeach
                            </div>
                        </div>
                    </div>
                    <figcaption class="mt-2 text-xs text-ink-500 dark:text-ink-400">{{ __('backdrop_admin.preview_web') }}</figcaption>
                </figure>
            </div>
        </aside>
    </form>

    @if ($canEdit)
        <x-admin.modal name="remove-picture" :title="__('backdrop_admin.remove_title')">
            <p class="mb-6 text-sm text-ink-600 dark:text-ink-300">{{ __('backdrop_admin.remove_body') }}</p>
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn-secondary min-h-11" autofocus @click="$dispatch('close-modal', 'remove-picture')">{{ __('admin.cancel') }}</button>
                <button type="button" class="btn-danger min-h-11" wire:click="removePicture" @click="$dispatch('close-modal', 'remove-picture')"><x-icon name="trash" class="size-4" />{{ __('backdrop_admin.remove_confirm') }}</button>
            </div>
        </x-admin.modal>

        <x-admin.modal name="reset-backdrop" :title="__('backdrop_admin.reset_title')">
            <p class="mb-6 text-sm text-ink-600 dark:text-ink-300">{{ __('backdrop_admin.reset_body') }}</p>
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn-secondary min-h-11" autofocus @click="$dispatch('close-modal', 'reset-backdrop')">{{ __('admin.cancel') }}</button>
                <button type="button" class="btn-primary min-h-11" wire:click="resetToDefault" @click="$dispatch('close-modal', 'reset-backdrop')">{{ __('backdrop_admin.reset_confirm') }}</button>
            </div>
        </x-admin.modal>
    @endif
</div>
