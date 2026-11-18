@php
    $channelNames = ['push' => __('cart_reminders.ch_push'), 'in_app' => __('cart_reminders.ch_in_app'), 'whatsapp' => __('cart_reminders.ch_whatsapp')];
    $chosen = array_values(array_filter(array_keys($channelNames), fn ($c) => $settings['channels'][$c] ?? false));
    $pending = array_values(array_filter($chosen, fn ($c) => ! ($ready[$c] ?? false)));
    $moneyRevenue = preg_replace('/\.00(?=\s|$)/', '', \App\Support\Money::of($results['revenue'])->format());
    $period = 'inline-flex min-h-11 items-center rounded-[0.65rem] px-3 text-sm font-medium tabular-nums transition';
@endphp
<div>
    {{-- ───────── The card: one switch, one sentence, the results ───────── --}}
    <section class="card mb-6 overflow-hidden" aria-labelledby="auto-reminders-title">
        <div class="flex flex-col gap-5 p-5 sm:p-6 lg:flex-row lg:items-start lg:justify-between">
            <div class="flex min-w-0 gap-4">
                <span class="hidden size-12 shrink-0 place-items-center rounded-2xl bg-brass-50 text-brass-600 dark:bg-brass-500/15 dark:text-brass-300 sm:grid"><x-icon name="send" class="size-6" /></span>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 id="auto-reminders-title" class="font-display text-xl font-semibold text-ink-900 dark:text-ivory">{{ __('cart_reminders.title') }}</h2>
                        <span class="{{ $enabled ? 'badge-success' : 'badge-neutral' }}" data-state="{{ $enabled ? 'on' : 'off' }}">{{ $enabled ? __('cart_reminders.state_on') : __('cart_reminders.state_off') }}</span>
                    </div>
                    <p class="mt-1.5 max-w-2xl text-sm leading-relaxed text-ink-700 dark:text-sand-200" data-summary>
                        @unless ($enabled)<span class="font-medium text-ink-500">{{ __('cart_reminders.when_on') }}</span> @endunless{{ $summary }}
                    </p>
                    <p class="mt-2.5 flex flex-wrap items-center gap-x-2 gap-y-1.5 text-xs text-ink-500 dark:text-ink-400">
                        <span>{{ __('cart_reminders.sent_by') }}</span>
                        @foreach ($chosen as $c)
                            <span class="badge-neutral">{{ $channelNames[$c] }}@unless ($ready[$c] ?? false) · {{ __('cart_reminders.not_set_up') }}@endunless</span>
                        @endforeach
                    </p>
                    <p class="mt-2 flex items-start gap-1.5 text-xs text-ink-500 dark:text-ink-400"><x-icon name="shield" class="mt-0.5 size-3.5 shrink-0 text-emerald-soft" /><span>{{ __('cart_reminders.safe_note') }}</span></p>
                </div>
            </div>

            <div class="flex shrink-0 items-center justify-between gap-4 lg:flex-col lg:items-end lg:gap-3">
                <div class="flex items-center gap-3">
                    <button type="button" role="switch" aria-checked="{{ $enabled ? 'true' : 'false' }}" aria-labelledby="auto-reminders-title" wire:click="toggle" wire:loading.attr="disabled" wire:target="toggle,undoToggle"
                            @disabled(! $canEdit)
                            class="relative inline-flex h-11 w-[4.5rem] shrink-0 items-center rounded-full p-1 transition focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-50 {{ $enabled ? 'bg-emerald-soft' : 'bg-sand-300 dark:bg-ink-700' }}">
                        <span class="size-9 rounded-full bg-white shadow transition-transform {{ $enabled ? 'ltr:translate-x-7 rtl:-translate-x-7' : '' }}"></span>
                    </button>
                </div>
                @if ($canEdit)
                    <button type="button" wire:click="openSettings" wire:loading.attr="disabled" wire:target="openSettings" class="btn-secondary min-h-11"><x-icon name="sliders" class="size-4" />{{ __('cart_reminders.settings') }}</button>
                @else
                    <p class="max-w-[14rem] text-xs text-ink-500 lg:text-end">{{ __('cart_reminders.read_only') }}</p>
                @endif
            </div>
        </div>

        @if ($blocked)
            <div class="mx-5 mb-5 flex items-start gap-3 rounded-2xl border border-amber-soft/30 bg-amber-soft/10 p-4 text-sm sm:mx-6" role="note">
                <x-icon name="alert" class="mt-0.5 size-5 shrink-0 text-amber-soft" />
                <p>{{ __('cart_reminders.blocked_'.$blocked) }} @can('notifications.view')<a class="font-medium underline" href="{{ route('admin.notification-rules') }}">{{ __('cart_reminders.open_controls') }}</a>@endcan</p>
            </div>
        @elseif ($enabled && $pending !== [])
            <div class="mx-5 mb-5 flex items-start gap-3 rounded-2xl border border-sand-300 bg-sand-50 p-4 text-sm dark:border-ink-700 dark:bg-ink-800 sm:mx-6" role="note">
                <x-icon name="info" class="mt-0.5 size-5 shrink-0 text-ink-400" />
                <p>{{ __('cart_reminders.channel_pending', ['channels' => collect($pending)->map(fn ($c) => $channelNames[$c])->implode(' · ')]) }}</p>
            </div>
        @endif

        {{-- Results --}}
        <div class="border-t border-sand-200/70 bg-sand-50/60 p-5 dark:border-ink-800 dark:bg-ink-900/60 sm:p-6" wire:loading.class="opacity-60" wire:target="setDays">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-ink-500">{{ __('cart_reminders.results') }}</h3>
                <div role="radiogroup" aria-label="{{ __('cart_reminders.period') }}" class="inline-flex rounded-xl border border-sand-300 bg-white p-0.5 dark:border-ink-600 dark:bg-ink-800">
                    @foreach ([7, 30] as $d)
                        <button type="button" role="radio" aria-checked="{{ $days === $d ? 'true' : 'false' }}" wire:click="setDays({{ $d }})"
                                class="{{ $period }} {{ $days === $d ? 'bg-ink-900 text-ivory dark:bg-brass-500 dark:text-ink-950' : 'text-ink-500 hover:text-ink-900 dark:hover:text-ivory' }}">{{ __('cart_reminders.last_n_days', ['n' => $d]) }}</button>
                    @endforeach
                </div>
            </div>
            <dl class="grid grid-cols-2 gap-x-4 gap-y-5 lg:grid-cols-4" data-results>
                <div><dt class="text-xs text-ink-500">{{ __('cart_reminders.res_sent') }}</dt><dd class="mt-1 font-display text-2xl font-semibold tabular-nums text-ink-900 dark:text-ivory sm:text-3xl" data-result="sent">{{ number_format($results['sent']) }}</dd><dd class="mt-0.5 text-xs text-ink-500">{{ __('cart_reminders.res_sent_hint') }}</dd></div>
                <div><dt class="text-xs text-ink-500">{{ __('cart_reminders.res_recovered') }}</dt><dd class="mt-1 font-display text-2xl font-semibold tabular-nums text-ink-900 dark:text-ivory sm:text-3xl" data-result="recovered">{{ number_format($results['recovered']) }}</dd><dd class="mt-0.5 text-xs text-ink-500">{{ __('cart_reminders.res_recovered_hint') }}</dd></div>
                <div><dt class="text-xs text-ink-500">{{ __('cart_reminders.res_revenue') }}</dt><dd class="mt-1 font-display text-2xl font-semibold tabular-nums text-ink-900 dark:text-ivory sm:text-3xl" dir="auto" data-result="revenue">{{ $moneyRevenue }}</dd><dd class="mt-0.5 text-xs text-ink-500">{{ __('cart_reminders.res_revenue_hint') }}</dd></div>
                <div><dt class="text-xs text-ink-500">{{ __('cart_reminders.res_failed') }}</dt><dd class="mt-1 font-display text-2xl font-semibold tabular-nums sm:text-3xl {{ $results['failed'] > 0 ? 'text-amber-soft' : 'text-ink-900 dark:text-ivory' }}" data-result="failed">{{ number_format($results['failed']) }}</dd><dd class="mt-0.5 text-xs text-ink-500">{{ $results['failed'] > 0 ? __('cart_reminders.res_failed_hint') : __('cart_reminders.res_failed_none') }}</dd></div>
            </dl>
            @if ($results['sent'] === 0 && $results['failed'] === 0)
                <p class="mt-4 text-xs text-ink-500">{{ $enabled ? __('cart_reminders.res_empty_on') : __('cart_reminders.res_empty_off') }}</p>
            @endif
        </div>
    </section>

    {{-- ───────── The settings window ───────── --}}
    @if ($canEdit)
        <x-admin.dialog wire:model="showSettings" max-width="max-w-2xl" :title="__('cart_reminders.window_title')" :description="__('cart_reminders.window_hint')">
            @if ($window)
                @include('livewire.admin.sales.abandoned-auto-reminders-window')
            @endif
            <x-slot:footer>
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <button type="button" @click="requestClose()" class="btn-ghost min-h-11">{{ __('admin.cancel') }}</button>
                    <button type="submit" form="cart-reminders-form" wire:loading.attr="disabled" wire:target="save" @disabled(! ($window['dirty'] ?? false)) class="btn-primary min-h-12 sm:min-w-44">
                        <x-icon name="check" class="size-4" /><span wire:loading.remove wire:target="save">{{ __('admin.save_changes') }}</span><span wire:loading wire:target="save">{{ __('cart_reminders.saving') }}</span>
                    </button>
                </div>
            </x-slot:footer>
        </x-admin.dialog>
    @endif
</div>
