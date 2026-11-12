@php
    $loc = app()->getLocale();
    $accent = ($form['accent'] ?? null) ?: ($primary ?? '#8A1538');
    $preview = collect($form['items'] ?? [])->filter(fn ($i) => ! empty($i['enabled']))->map(fn ($i) => [
        'key' => $i['key'],
        'icon' => $i['icon'] ?: ($defaultIcons[$i['key']] ?? 'star'),
        'label' => trim((string) ($i['label_'.$loc] ?? '')) ?: __('footer_admin.short.'.$i['key']),
    ])->values()->all();
    $sample = array_slice($preview, 0, 5);
    $items = $form['items'] ?? [];
    $freeKeys = array_diff($itemKeys, array_column($items, 'key'));
@endphp
<div>
    <x-admin.page-header :title="__('footer_admin.title')" :eyebrow="__('nav.appearance')" :description="__('footer_admin.hint')">
        <x-slot:actions>
            <a href="{{ route('admin.appearance.index') }}" class="btn-secondary" wire:navigate><x-icon name="chevron-left" class="size-4 rtl:rotate-180" />{{ __('footer_admin.back') }}</a>
            @if ($canEdit)<button type="button" class="btn-secondary" @click="$dispatch('open-modal', 'reset-footer')"><x-icon name="refresh" class="size-4" />{{ __('footer_admin.reset') }}</button>@endif
        </x-slot:actions>
    </x-admin.page-header>

    <form wire:submit="save" class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">
        <div class="space-y-6 [&>*]:min-w-0">
            <section class="card p-4 sm:p-6" aria-labelledby="style-h">
                <h2 id="style-h" class="mb-3 font-display text-lg font-semibold">{{ __('footer_admin.style') }}</h2>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" role="radiogroup" aria-labelledby="style-h">
                    @foreach ($designs as $d)
                        @php $sel = ($form['design'] ?? 'classic') === $d; @endphp
                        <label class="relative cursor-pointer rounded-2xl border p-3 transition focus-within:ring-2 focus-within:ring-brass-500 {{ $sel ? 'border-brass-500 bg-brass-500/5 ring-1 ring-brass-500' : 'border-sand-200 hover:border-brass-400 dark:border-ink-700' }}">
                            <input type="radio" name="design" value="{{ $d }}" class="sr-only" wire:click="pickDesign('{{ $d }}')" @checked($sel) @disabled(! $canEdit)>
                            <div class="mb-3 flex h-24 flex-col justify-end overflow-hidden rounded-xl bg-sand-50 dark:bg-ink-900">
                                <x-admin.footer-mock :design="$d" :items="$sample" :active="0" :labels="$form['show_labels'] ?? true" :accent="$accent" :badge="$form['cart_badge'] ?? true" />
                            </div>
                            <p class="flex items-center justify-between text-sm font-semibold">{{ __('footer_admin.design_'.$d) }}@if ($sel)<x-icon name="check" class="size-4 text-brass-600" />@endif</p>
                            <p class="mt-0.5 text-xs text-ink-500">{{ __('footer_admin.design_'.$d.'_hint') }}</p>
                        </label>
                    @endforeach
                </div>
            </section>

            <section class="card p-4 sm:p-6" aria-labelledby="items-h">
                <h2 id="items-h" class="font-display text-lg font-semibold">{{ __('footer_admin.items') }}</h2>
                <p class="mb-4 text-sm text-ink-500">{{ __('footer_admin.items_hint') }}</p>
                @error('form.items')<p class="field-error mb-3" role="alert">{{ $message }}</p>@enderror
                <ol class="space-y-3">
                    @foreach ($items as $i => $it)
                        @php $isHome = $it['key'] === 'home'; @endphp
                        <li class="rounded-2xl border border-sand-200/80 p-3 dark:border-ink-700" wire:key="fi-{{ $it['key'] }}">
                            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-[1fr_1fr_auto] [&>*]:min-w-0">
                                <x-admin.field :label="__('footer_admin.destination')" :for="'fi-key-'.$i">
                                    <select id="fi-key-{{ $i }}" wire:model.live="form.items.{{ $i }}.key" class="input" @disabled($isHome || ! $canEdit)>
                                        @foreach ($itemKeys as $k)
                                            @if ($k === $it['key'] || in_array($k, $freeKeys, true))<option value="{{ $k }}">{{ __('footer_admin.item_'.$k) }}</option>@endif
                                        @endforeach
                                    </select>
                                </x-admin.field>
                                <x-admin.field :label="__('footer_admin.icon')" :for="'fi-icon-'.$i">
                                    <select id="fi-icon-{{ $i }}" wire:model.live="form.items.{{ $i }}.icon" class="input" @disabled(! $canEdit)>
                                        <option value="">{{ __('footer_admin.icon_default') }}</option>
                                        @foreach ($icons as $ic)<option value="{{ $ic }}">{{ __('footer_admin.icon_'.$ic) }}</option>@endforeach
                                    </select>
                                </x-admin.field>
                                <div class="flex items-end gap-1 sm:col-span-2 xl:col-span-1">
                                    <label class="flex min-h-11 items-center gap-2 rounded-xl px-2 text-sm">
                                        <input type="checkbox" class="checkbox" wire:model.live="form.items.{{ $i }}.enabled" @disabled($isHome || ! $canEdit)>
                                        {{ $isHome ? __('footer_admin.always_on') : __('footer_admin.on') }}
                                    </label>
                                    @if ($canEdit)
                                        <button type="button" wire:click="move({{ $i }}, -1)" class="btn-ghost size-11 justify-center p-0" aria-label="{{ __('footer_admin.move_up') }}" @disabled($i === 0)><x-icon name="arrow-up-right" class="size-4 -rotate-45" /></button>
                                        <button type="button" wire:click="move({{ $i }}, 1)" class="btn-ghost size-11 justify-center p-0" aria-label="{{ __('footer_admin.move_down') }}" @disabled($i === count($items) - 1)><x-icon name="chevron-down" class="size-4" /></button>
                                        @if (! $isHome && count($items) > 3)<button type="button" wire:click="removeItem({{ $i }})" class="btn-ghost size-11 justify-center p-0 text-ruby-soft" aria-label="{{ __('footer_admin.remove') }}"><x-icon name="trash" class="size-4" /></button>@endif
                                    @endif
                                </div>
                                <x-admin.field :label="__('footer_admin.label_en')" :for="'fi-en-'.$i"><input id="fi-en-{{ $i }}" wire:model.live.debounce.400ms="form.items.{{ $i }}.label_en" class="input" dir="ltr" maxlength="20" placeholder="{{ __('footer_admin.short.'.$it['key']) }}" @disabled(! $canEdit)></x-admin.field>
                                <x-admin.field :label="__('footer_admin.label_ar')" :for="'fi-ar-'.$i"><input id="fi-ar-{{ $i }}" wire:model.live.debounce.400ms="form.items.{{ $i }}.label_ar" class="input" dir="rtl" maxlength="20" @disabled(! $canEdit)></x-admin.field>
                            </div>
                            @error('form.items.'.$i.'.label_en')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                            @error('form.items.'.$i.'.label_ar')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                            @error('form.items.'.$i.'.key')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        </li>
                    @endforeach
                </ol>
                @if ($canEdit && count($items) < $max && $freeKeys)
                    <button type="button" wire:click="addItem" class="btn-secondary mt-3"><x-icon name="plus" class="size-4" />{{ __('footer_admin.add') }}</button>
                @endif
            </section>

            <section class="card p-4 sm:p-6" aria-labelledby="opt-h">
                <h2 id="opt-h" class="mb-3 font-display text-lg font-semibold">{{ __('footer_admin.options') }}</h2>
                <div class="grid gap-3 sm:grid-cols-2 [&>*]:min-w-0">
                    <label class="flex min-h-11 items-center justify-between gap-4 rounded-xl border border-sand-100 px-4 py-2 dark:border-ink-800"><span class="text-sm">{{ __('footer_admin.show_labels') }}</span><input type="checkbox" class="checkbox" wire:model.live="form.show_labels" @disabled(! $canEdit)></label>
                    <label class="flex min-h-11 items-center justify-between gap-4 rounded-xl border border-sand-100 px-4 py-2 dark:border-ink-800"><span class="text-sm">{{ __('footer_admin.cart_badge') }}</span><input type="checkbox" class="checkbox" wire:model.live="form.cart_badge" @disabled(! $canEdit)></label>
                    <div class="sm:col-span-2">
                        <span class="field-label">{{ __('footer_admin.accent') }}</span>
                        <div class="flex flex-wrap items-center gap-3">
                            <input type="color" value="{{ $accent }}" wire:change="$set('form.accent', $event.target.value)" class="size-11 cursor-pointer rounded-lg border-0 bg-transparent p-0" aria-label="{{ __('footer_admin.accent') }}" @disabled(! $canEdit)>
                            <span class="font-mono text-xs uppercase text-ink-500" dir="ltr">{{ $form['accent'] ?? $accent }}</span>
                            @if ($canEdit && ! empty($form['accent']))<button type="button" wire:click="clearAccent" class="btn-ghost btn-sm">{{ __('footer_admin.use_theme_colour') }}</button>@endif
                        </div>
                        <p class="field-hint">{{ __('footer_admin.accent_hint') }}</p>
                        @error('form.accent')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                </div>
            </section>

            @if ($canEdit)
                <div class="flex justify-end">
                    <button type="submit" class="btn-primary" @disabled(! $dirty) wire:loading.attr="disabled" wire:target="save" aria-live="polite">
                        <x-icon name="check" class="size-4" />
                        <span wire:loading.remove wire:target="save">{{ $justSaved && ! $dirty ? __('footer_admin.saved') : __('footer_admin.save') }}</span>
                        <span wire:loading wire:target="save">{{ __('footer_admin.saving') }}</span>
                    </button>
                </div>
            @endif
        </div>

        <aside class="lg:sticky lg:top-6" aria-labelledby="pv-h">
            <h2 id="pv-h" class="mb-1 font-display text-lg font-semibold">{{ __('footer_admin.preview') }}</h2>
            <p class="mb-3 text-sm text-ink-500">{{ __('footer_admin.preview_hint') }}</p>
            <div class="mx-auto flex h-[26rem] w-60 flex-col overflow-hidden rounded-[30px] border-4 border-ink-900 bg-sand-50 shadow-soft dark:border-ink-600 dark:bg-ink-900">
                <div class="px-4 pb-8 pt-5" style="background: linear-gradient(135deg, {{ $primary ?? '#8A1538' }}, {{ $primary ?? '#8A1538' }}cc)">
                    <p class="text-lg font-bold text-white">Al Bahar</p>
                </div>
                <div class="-mt-4 flex-1 space-y-2 px-3">
                    <div class="h-20 rounded-2xl bg-white shadow-sm dark:bg-ink-800"></div>
                    <div class="h-14 rounded-2xl bg-white shadow-sm dark:bg-ink-800"></div>
                </div>
                <x-admin.footer-mock large :design="$form['design'] ?? 'classic'" :items="$preview" :active="0" :labels="$form['show_labels'] ?? true" :accent="$accent" :badge="$form['cart_badge'] ?? true" />
            </div>
        </aside>
    </form>

    <x-admin.modal name="reset-footer" :title="__('footer_admin.reset_title')">
        <p class="mb-6 text-sm text-ink-600 dark:text-ink-300">{{ __('footer_admin.reset_body') }}</p>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button type="button" class="btn-secondary" @click="$dispatch('close-modal', 'reset-footer')">{{ __('admin.cancel') }}</button>
            <button type="button" class="btn-primary" wire:click="resetToDefault" @click="$dispatch('close-modal', 'reset-footer')">{{ __('footer_admin.reset_confirm') }}</button>
        </div>
    </x-admin.modal>
</div>
