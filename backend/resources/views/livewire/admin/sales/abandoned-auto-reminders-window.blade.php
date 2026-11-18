{{-- "Reminder settings" side window. Needs $window (see AbandonedAutoReminders::windowData), $form, $tab, $editing, $previewLocale. Everything here waits for Save. --}}
@php
    $svc = \App\Domain\Marketing\Services\AbandonedCartReminders::class;
    $label = fn (int $m) => $svc::delayLabel($m);
    $box = 'rounded-2xl border border-sand-200 p-4 dark:border-ink-700';
    $h3 = 'text-xs font-semibold uppercase tracking-wider text-ink-500';
    $pill = 'grid min-h-11 min-w-11 place-items-center rounded-xl border border-sand-300 px-3 text-sm font-medium tabular-nums transition peer-checked:border-ink-900 peer-checked:bg-ink-900 peer-checked:text-ivory peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-disabled:opacity-40 dark:border-ink-600 dark:peer-checked:border-brass-500 dark:peer-checked:bg-brass-500 dark:peer-checked:text-ink-950';
    $channels = ['push' => [__('cart_reminders.ch_push'), __('cart_reminders.ch_push_hint')], 'in_app' => [__('cart_reminders.ch_in_app'), __('cart_reminders.ch_in_app_hint')], 'whatsapp' => [__('cart_reminders.ch_whatsapp'), __('cart_reminders.ch_whatsapp_hint')]];
    $presetKeys = array_keys($svc::PRESETS);
    $tok = fn (string $p) => '{'.'{'.$p.'}'.'}'; // the {{placeholder}} text, built so Blade does not read it as an echo
    $tabs = ['when' => __('cart_reminders.tab_when'), 'messages' => __('cart_reminders.tab_messages')];
    $durations = [24, 48, 72, 168];
    if (! in_array((int) $form['offer']['valid_hours'], $durations, true)) { $durations[] = (int) $form['offer']['valid_hours']; sort($durations); }
@endphp
<form id="cart-reminders-form" wire:submit="save" class="space-y-6 pb-4" novalidate
      @keydown.enter="if ($event.target.tagName === 'INPUT') { $event.preventDefault(); }"
      x-data="{
          target: null,
          insert(text) {
              const el = this.target;
              if (! el) { return; }
              const s = el.selectionStart ?? el.value.length, e = el.selectionEnd ?? s;
              el.value = el.value.slice(0, s) + text + el.value.slice(e);
              el.dispatchEvent(new Event('input', { bubbles: true }));
              el.focus();
              el.setSelectionRange(s + text.length, s + text.length);
          },
      }"
      @focusin="if ($event.target.matches && $event.target.matches('[data-ph]')) { target = $event.target; }">

    {{-- What these settings do, in one sentence (the same sentence the card shows once saved) --}}
    <div class="flex items-start gap-3 rounded-2xl bg-brass-50 p-4 text-sm leading-relaxed text-ink-800 dark:bg-brass-500/10 dark:text-sand-100" role="status" aria-live="polite" data-window-summary>
        <x-icon name="info" class="mt-0.5 size-5 shrink-0 text-brass-600 dark:text-brass-300" /><p>{{ $window['summary'] }}</p>
    </div>

    <div role="tablist" aria-label="{{ __('cart_reminders.window_title') }}" class="inline-flex w-full rounded-xl border border-sand-300 bg-sand-50 p-0.5 dark:border-ink-600 dark:bg-ink-800 sm:w-auto">
        @foreach ($tabs as $key => $text)
            <button type="button" role="tab" id="cr-tab-{{ $key }}" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" aria-controls="cr-panel-{{ $key }}" wire:click="setTab('{{ $key }}')"
                    class="min-h-11 flex-1 rounded-[0.65rem] px-4 text-sm font-medium transition sm:flex-none {{ $tab === $key ? 'bg-white text-ink-900 shadow-sm dark:bg-ink-700 dark:text-ivory' : 'text-ink-500 hover:text-ink-900 dark:hover:text-ivory' }}">{{ $text }}</button>
        @endforeach
    </div>

    @if ($tab === 'when')
    <div id="cr-panel-when" role="tabpanel" aria-labelledby="cr-tab-when" class="space-y-6">
        {{-- Ready-made schedules --}}
        <section aria-labelledby="cr-presets">
            <h3 id="cr-presets" class="{{ $h3 }} mb-2">{{ __('cart_reminders.quick_setup') }}</h3>
            <div class="grid gap-2 sm:grid-cols-3">
                @foreach ($presetKeys as $p)
                    <button type="button" @click="dirty = true" wire:click="preset('{{ $p }}')" aria-pressed="{{ $window['preset'] === $p ? 'true' : 'false' }}"
                            class="rounded-2xl border p-3 text-start transition hover:border-brass-400 {{ $window['preset'] === $p ? 'border-ink-900 bg-ink-900 text-ivory dark:border-brass-500 dark:bg-brass-500 dark:text-ink-950' : 'border-sand-300 bg-white dark:border-ink-600 dark:bg-ink-800' }}">
                        <span class="block text-sm font-semibold">{{ __('cart_reminders.preset_'.$p) }}</span>
                        <span class="mt-0.5 block text-xs {{ $window['preset'] === $p ? 'opacity-80' : 'text-ink-500' }}">{{ __('cart_reminders.preset_'.$p.'_hint') }}</span>
                    </button>
                @endforeach
            </div>
        </section>

        {{-- The reminders --}}
        <section aria-labelledby="cr-reminders" class="space-y-2">
            <h3 id="cr-reminders" class="{{ $h3 }}">{{ __('cart_reminders.reminders') }}</h3>
            <p class="text-xs text-ink-500">{{ __('cart_reminders.reminders_hint') }}</p>
            @foreach ($form['reminders'] as $n => $r)
                <div class="{{ $box }}" wire:key="cr-r-{{ $n }}">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <label class="flex min-h-11 cursor-pointer items-center gap-3">
                            <input type="checkbox" class="checkbox" wire:model.live="form.reminders.{{ $n }}.enabled">
                            <span class="text-sm font-medium text-ink-900 dark:text-ivory">{{ __('cart_reminders.reminder_n', ['n' => $n + 1]) }}</span>
                        </label>
                        <label class="flex items-center gap-2 text-sm text-ink-600 dark:text-sand-200">
                            <span>{{ __('cart_reminders.after') }}</span>
                            <select wire:model.live="form.reminders.{{ $n }}.delay" class="input w-auto min-w-28" @disabled(! $r['enabled']) aria-label="{{ __('cart_reminders.reminder_n_after', ['n' => $n + 1]) }}">
                                @foreach ($window['choices'][$n] as $m)<option value="{{ $m }}">{{ $label($m) }}</option>@endforeach
                            </select>
                        </label>
                    </div>
                    @error('form.reminders.'.$n.'.delay')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    @error('form.reminders.'.$n.'.enabled')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                </div>
            @endforeach
        </section>

        {{-- Most per cart --}}
        <fieldset class="space-y-2">
            <legend class="{{ $h3 }}">{{ __('cart_reminders.max_per_cart') }}</legend>
            <div role="radiogroup" class="flex flex-wrap gap-2">
                @foreach (range(1, 5) as $n)
                    <label class="relative cursor-pointer"><input type="radio" class="peer sr-only" wire:model.live="form.max_per_cart" value="{{ $n }}"><span class="{{ $pill }}">{{ $n }}</span></label>
                @endforeach
            </div>
            <p class="text-xs text-ink-500">{{ __('cart_reminders.max_per_cart_hint') }}</p>
            @error('form.max_per_cart')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        </fieldset>

        {{-- Quiet hours --}}
        <section class="{{ $box }} space-y-3" aria-labelledby="cr-quiet">
            <h3 id="cr-quiet" class="{{ $h3 }}">{{ __('cart_reminders.quiet') }}</h3>
            <label class="flex min-h-11 cursor-pointer items-center gap-3"><input type="checkbox" class="checkbox" wire:model.live="form.quiet"><span class="text-sm text-ink-800 dark:text-sand-100">{{ __('cart_reminders.quiet_on') }}</span></label>
            @if ($form['quiet'])
                <div class="grid grid-cols-2 gap-3">
                    <x-admin.field :label="__('cart_reminders.quiet_from')" for="cr-quiet-from"><input id="cr-quiet-from" type="time" wire:model.live="form.quiet_start" class="input tabular-nums @error('form.quiet_start') input-error @enderror" dir="ltr"></x-admin.field>
                    <x-admin.field :label="__('cart_reminders.quiet_to')" for="cr-quiet-to"><input id="cr-quiet-to" type="time" wire:model.live="form.quiet_end" class="input tabular-nums @error('form.quiet_end') input-error @enderror" dir="ltr"></x-admin.field>
                </div>
                @error('form.quiet_start')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                @error('form.quiet_end')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                <p class="text-xs text-ink-500">{{ __('cart_reminders.quiet_hint', ['hours' => $svc::GRACE_HOURS]) }}</p>
            @else
                <p class="text-xs text-ink-500">{{ __('cart_reminders.quiet_off_hint') }}</p>
            @endif
        </section>

        {{-- Ways to reach the customer --}}
        <fieldset class="space-y-2">
            <legend class="{{ $h3 }}">{{ __('cart_reminders.channels') }}</legend>
            @foreach ($channels as $key => [$name, $hint])
                @php $off = $window['ruleOff'][$key] ?? false; $isReady = ($ready[$key] ?? false) && ! $off; @endphp
                <x-admin.toggle :label="$name" :hint="$off ? $hint.' · '.__('cart_reminders.off_in_controls') : ($isReady ? $hint : $hint.' · '.__('cart_reminders.not_set_up_yet'))" wire:model.live="form.channels.{{ $key }}" wire:key="cr-ch-{{ $key }}" :disabled="! $isReady" :class="$isReady ? '' : 'opacity-50'" />
            @endforeach
            <p class="text-xs text-ink-500"><x-icon name="shield" class="me-1 inline size-3.5 text-emerald-soft" />{{ __('cart_reminders.consent_note') }}</p>
            @error('form.channels')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        </fieldset>

        {{-- Recovery offer --}}
        <section class="{{ $box }} space-y-3" aria-labelledby="cr-offer">
            <h3 id="cr-offer" class="{{ $h3 }}">{{ __('cart_reminders.offer') }}</h3>
            <x-admin.field :label="__('cart_reminders.offer_promotion')" for="cr-offer-promotion" :hint="__('cart_reminders.offer_hint')">
                <select id="cr-offer-promotion" wire:model.live="form.offer.promotion_id" class="input @error('form.offer.promotion_id') input-error @enderror">
                    <option value="">{{ __('cart_reminders.offer_none') }}</option>
                    @foreach ($window['promotions'] as $promotion)<option value="{{ $promotion->id }}">{{ $promotion->localized('name') }}</option>@endforeach
                </select>
            </x-admin.field>
            @error('form.offer.promotion_id')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            @if ($window['hasOffer'])
                <fieldset>
                    <legend class="field-label">{{ __('cart_reminders.offer_with') }}</legend>
                    <div role="radiogroup" class="flex flex-wrap gap-2">
                        @foreach ($form['reminders'] as $n => $r)
                            <label class="relative cursor-pointer"><input type="radio" class="peer sr-only" wire:model.live="form.offer.reminder" value="{{ $n + 1 }}" @disabled(! $r['enabled'])><span class="{{ $pill }}">{{ __('cart_reminders.reminder_n', ['n' => $n + 1]) }}</span></label>
                        @endforeach
                    </div>
                </fieldset>
                @error('form.offer.reminder')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                <x-admin.field :label="__('cart_reminders.offer_valid')" for="cr-offer-valid">
                    <select id="cr-offer-valid" wire:model.live="form.offer.valid_hours" class="input w-auto min-w-36">
                        @foreach ($durations as $h)<option value="{{ $h }}">{{ $h % 24 === 0 ? trans_choice('cart_reminders.n_days', intdiv($h, 24), ['n' => intdiv($h, 24)]) : trans_choice('cart_reminders.n_hours', $h, ['n' => $h]) }}</option>@endforeach
                    </select>
                </x-admin.field>
                <p class="text-xs text-ink-500">{{ __('cart_reminders.offer_added_note') }}</p>
            @endif
        </section>
    </div>
    @else
    <div id="cr-panel-messages" role="tabpanel" aria-labelledby="cr-tab-messages" class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div role="radiogroup" aria-label="{{ __('cart_reminders.reminders') }}" class="inline-flex rounded-xl border border-sand-300 bg-sand-50 p-0.5 dark:border-ink-600 dark:bg-ink-800">
                @foreach ($form['reminders'] as $n => $r)
                    <button type="button" role="radio" aria-checked="{{ $editing === $n + 1 ? 'true' : 'false' }}" wire:click="setEditing({{ $n + 1 }})"
                            class="min-h-11 rounded-[0.65rem] px-3.5 text-sm font-medium transition {{ $editing === $n + 1 ? 'bg-white text-ink-900 shadow-sm dark:bg-ink-700 dark:text-ivory' : 'text-ink-500 hover:text-ink-900 dark:hover:text-ivory' }} {{ $r['enabled'] ? '' : 'opacity-60' }}">{{ __('cart_reminders.reminder_n', ['n' => $n + 1]) }}@unless ($r['enabled']) · {{ __('cart_reminders.off') }}@endunless</button>
                @endforeach
            </div>
            <button type="button" @click="dirty = true" wire:click="resetText" class="btn-ghost btn-sm min-h-11"><x-icon name="refresh" class="size-4" />{{ __('cart_reminders.reset_text') }}</button>
        </div>
        @php $i = $editing - 1; @endphp

        {{-- Live preview --}}
        <section aria-labelledby="cr-preview" class="rounded-3xl bg-sand-100 p-4 dark:bg-ink-800">
            <div class="mb-3 flex items-center justify-between gap-3">
                <h3 id="cr-preview" class="{{ $h3 }}">{{ __('cart_reminders.preview') }}</h3>
                <div role="radiogroup" aria-label="{{ __('cart_reminders.preview_language') }}" class="inline-flex rounded-xl border border-sand-300 bg-white p-0.5 dark:border-ink-600 dark:bg-ink-900">
                    @foreach (['en' => 'English', 'ar' => 'العربية'] as $code => $name)
                        <button type="button" role="radio" aria-checked="{{ $previewLocale === $code ? 'true' : 'false' }}" wire:click="setPreviewLocale('{{ $code }}')"
                                class="min-h-11 rounded-[0.65rem] px-3 text-sm font-medium {{ $previewLocale === $code ? 'bg-ink-900 text-ivory dark:bg-brass-500 dark:text-ink-950' : 'text-ink-500' }}">{{ $name }}</button>
                    @endforeach
                </div>
            </div>
            <div class="mx-auto max-w-sm rounded-2xl bg-white p-3.5 shadow-md dark:bg-ink-700" dir="{{ $previewLocale === 'ar' ? 'rtl' : 'ltr' }}" lang="{{ $previewLocale }}" data-preview>
                <div class="flex items-center gap-2 text-[0.7rem] text-ink-500 dark:text-ink-300"><span class="grid size-5 place-items-center rounded-md bg-ink-900 text-[0.6rem] font-bold text-ivory">B</span><span class="font-semibold">{{ $previewLocale === 'ar' ? config('platform.brand.name_ar') : config('platform.brand.name_en') }}</span><span aria-hidden="true">·</span><span>{{ __('cart_reminders.preview_now', [], $previewLocale) }}</span></div>
                <p class="mt-1.5 text-sm font-semibold text-ink-900 dark:text-ivory" data-preview-title>{{ $window['preview']['title'] }}</p>
                <p class="mt-0.5 text-sm leading-relaxed text-ink-600 dark:text-sand-200" data-preview-body>{{ $window['preview']['body'] }}</p>
            </div>
            <p class="mt-3 text-center text-xs text-ink-500">{{ __('cart_reminders.preview_sample') }}</p>
        </section>

        {{-- Words --}}
        <section class="space-y-3" aria-labelledby="cr-words">
            <h3 id="cr-words" class="{{ $h3 }}">{{ __('cart_reminders.words', ['n' => $editing]) }}</h3>
            <div class="flex flex-wrap gap-1.5" aria-label="{{ __('cart_reminders.placeholders') }}">
                <span class="me-1 self-center text-xs text-ink-500">{{ __('cart_reminders.insert') }}</span>
                @foreach (\App\Domain\Marketing\Support\ReminderText::PLACEHOLDERS as $ph)
                    @php $needsOffer = in_array($ph, \App\Domain\Marketing\Support\ReminderText::OFFER_ONLY, true); $offerOnThis = $window['hasOffer'] && $window['offerSlot'] === $i; @endphp
                    <button type="button" @mousedown.prevent @click="insert($el.dataset.token)" data-token="{{ $tok($ph) }}" @disabled($needsOffer && ! $offerOnThis) title="{{ $tok($ph) }}"
                            class="inline-flex min-h-9 items-center rounded-full border border-sand-300 bg-white px-3 text-xs font-medium text-ink-700 transition hover:border-brass-400 disabled:cursor-not-allowed disabled:opacity-40 dark:border-ink-600 dark:bg-ink-800 dark:text-sand-100">{{ __('cart_reminders.ph.'.$ph) }}</button>
                @endforeach
            </div>
            @if ($window['customWhatsApp'])
                <p class="flex items-start gap-2 rounded-xl bg-sand-50 p-3 text-xs text-ink-600 dark:bg-ink-800 dark:text-sand-200"><x-icon name="whatsapp" class="mt-0.5 size-4 shrink-0 text-ink-400" />{{ __('cart_reminders.own_whatsapp') }}</p>
            @endif
            <div class="grid gap-4 md:grid-cols-2" wire:key="cr-words-{{ $editing }}">
                @foreach (['en' => 'English', 'ar' => 'العربية'] as $code => $name)
                    <div class="{{ $box }} space-y-3" lang="{{ $code }}">
                        <p class="text-sm font-semibold text-ink-900 dark:text-ivory">{{ $name }}</p>
                        <x-admin.field :label="__('cart_reminders.field_title')" :for="'cr-title-'.$code" error="form.reminders.{{ $i }}.title_{{ $code }}">
                            <input id="cr-title-{{ $code }}" type="text" data-ph wire:model.live.debounce.400ms="form.reminders.{{ $i }}.title_{{ $code }}" maxlength="80" dir="{{ $code === 'ar' ? 'rtl' : 'ltr' }}" class="input @error('form.reminders.'.$i.'.title_'.$code) input-error @enderror" autocomplete="off">
                        </x-admin.field>
                        <x-admin.field :label="__('cart_reminders.field_message')" :for="'cr-body-'.$code" error="form.reminders.{{ $i }}.body_{{ $code }}">
                            <textarea id="cr-body-{{ $code }}" rows="4" data-ph wire:model.live.debounce.400ms="form.reminders.{{ $i }}.body_{{ $code }}" maxlength="300" dir="{{ $code === 'ar' ? 'rtl' : 'ltr' }}" class="input @error('form.reminders.'.$i.'.body_'.$code) input-error @enderror"></textarea>
                        </x-admin.field>
                    </div>
                @endforeach
            </div>
            <p class="text-xs text-ink-500">{{ __('cart_reminders.words_hint') }}</p>
        </section>
    </div>
    @endif
</form>
