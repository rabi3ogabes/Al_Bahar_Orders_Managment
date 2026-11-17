@php
    // The row grid on large screens: name, master switch, then one narrow column per channel. Phones stack the same cells as cards.
    $cols = 'lg:grid lg:grid-cols-[minmax(0,1fr)_5rem_repeat(6,4.75rem)] lg:items-center lg:gap-x-2';
    $chip = 'inline-flex min-h-11 shrink-0 items-center rounded-full border px-4 text-sm transition focus-visible:outline-2 focus-visible:outline-offset-2';
    $chipOn = 'border-brass-500 bg-brass-50 font-semibold text-brass-700 dark:bg-brass-500/15 dark:text-brass-300';
    $chipOff = 'border-sand-300 bg-white text-ink-700 hover:border-brass-400 dark:border-ink-600 dark:bg-ink-800 dark:text-sand-100';
@endphp
<div>
    <x-admin.page-header :title="__('notification_rules_admin.title')" :eyebrow="__('nav.content')" :description="__('notification_rules_admin.hint')">
        <x-slot:actions>
            <a href="{{ route('admin.notification-templates.index') }}" class="btn-secondary" wire:navigate><x-icon name="edit" class="size-4" />{{ __('notification_rules_admin.message_texts') }}</a>
            @if ($canEdit && $anyCustomized)
                <button type="button" class="btn-secondary" @click="$dispatch('open-modal', 'reset-all-rules')"><x-icon name="refresh" class="size-4" />{{ __('notification_rules_admin.reset_all') }}</button>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mb-6 flex gap-3 rounded-2xl border border-sky-soft/25 bg-sky-soft/5 p-4 text-sm dark:border-sky-soft/40" role="note">
        <x-icon name="info" class="mt-0.5 size-5 shrink-0 text-sky-soft dark:text-sky-300" />
        <div class="min-w-0">
            <p class="font-semibold text-ink-900 dark:text-ivory">{{ __('notification_rules_admin.banner_title') }}</p>
            <p class="mt-0.5 text-ink-600 dark:text-sand-300">{{ __('notification_rules_admin.banner_body') }}</p>
            @unless ($canEdit)<p class="mt-2 font-medium text-amber-soft dark:text-amber-300">{{ __('notification_rules_admin.view_only') }}</p>@endunless
        </div>
    </div>

    @if ($setup !== [])
        <section class="mb-6 rounded-2xl border border-amber-soft/30 bg-amber-soft/5 p-4" aria-labelledby="rules-setup-h">
            <h2 id="rules-setup-h" class="flex items-center gap-2 text-sm font-semibold text-amber-soft dark:text-amber-300"><x-icon name="alert" class="size-4" />{{ __('notification_rules_admin.setup_title') }}</h2>
            <p class="mt-1 text-sm text-ink-600 dark:text-sand-300">{{ __('notification_rules_admin.setup_body') }}</p>
            <ul class="mt-3 flex flex-wrap gap-2">
                @foreach ($setup as $s)
                    <li class="inline-flex min-h-11 items-center gap-2 rounded-full bg-white px-4 text-sm shadow-sm dark:bg-ink-800" wire:key="setup-{{ $s['channel'] }}">
                        <span class="font-medium text-ink-900 dark:text-ivory">{{ $s['label'] }}</span>
                        <span class="text-ink-500">{{ __('notification_rules_admin.not_set_up') }}</span>
                        @if ($s['url'])<a href="{{ $s['url'] }}" wire:navigate class="font-semibold text-brass-700 underline underline-offset-2 dark:text-brass-300">{{ __('notification_rules_admin.setup_link') }}</a>@endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="mb-5 space-y-3">
        <div class="relative">
            <x-icon name="search" class="pointer-events-none absolute start-3.5 top-1/2 size-4 -translate-y-1/2 text-ink-400" />
            <input type="search" wire:model.live.debounce.250ms="q" class="input ps-10" placeholder="{{ __('notification_rules_admin.search') }}" aria-label="{{ __('notification_rules_admin.search') }}">
        </div>
        <div role="group" aria-label="{{ __('notification_rules_admin.filter_label') }}" class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
            <button type="button" wire:click="clearFilters" aria-pressed="{{ $only === [] ? 'true' : 'false' }}" class="{{ $chip }} {{ $only === [] ? $chipOn : $chipOff }}">{{ __('notification_rules_admin.all') }}</button>
            @foreach ($groupChips as $g)
                <button type="button" wire:key="chip-{{ $g['key'] }}" wire:click="toggleGroup('{{ $g['key'] }}')" aria-pressed="{{ in_array($g['key'], $only, true) ? 'true' : 'false' }}" class="{{ $chip }} {{ in_array($g['key'], $only, true) ? $chipOn : $chipOff }}">{{ $g['label'] }}</button>
            @endforeach
        </div>
        <p class="text-sm text-ink-500" aria-live="polite">{{ trans_choice('notification_rules_admin.results', $total, ['count' => $total]) }}</p>
    </div>

    @forelse ($sections as $section)
        <section class="card mb-6 overflow-hidden" aria-labelledby="grp-{{ $section['group'] }}" wire:key="grp-{{ $section['group'] }}">
            <div class="flex items-baseline justify-between gap-3 px-5 pt-5 sm:px-6">
                <h2 id="grp-{{ $section['group'] }}" class="font-display text-xl font-semibold">{{ $section['label'] }}</h2>
                <span class="text-xs tabular-nums text-ink-500">{{ count($section['rows']) }}</span>
            </div>

            {{-- Column titles (large screens only; on a phone every switch carries its own label). --}}
            <div class="{{ $cols }} mt-3 hidden border-b border-sand-100 px-5 pb-2 text-[0.7rem] font-semibold uppercase tracking-wide text-ink-500 sm:px-6 dark:border-ink-800" aria-hidden="true">
                <span></span>
                <span class="text-center">{{ __('notification_rules_admin.col_master') }}</span>
                @foreach ($columns as $ch)
                    <span class="text-center" title="{{ __('notification_rules_admin.channel_hints.'.$ch) }}">
                        {{ __('notification_rules_admin.channels.'.$ch) }}
                        @unless ($channelStatus[$ch] ?? false)<span class="block text-[0.6rem] font-medium normal-case tracking-normal text-amber-soft">{{ __('notification_rules_admin.not_set_up') }}</span>@endunless
                    </span>
                @endforeach
            </div>

            <ul class="divide-y divide-sand-100 dark:divide-ink-800">
                @foreach ($section['rows'] as $row)
                    <li wire:key="rule-{{ $row['event'] }}" class="{{ $cols }} px-5 py-4 sm:px-6 {{ $row['enabled'] ? '' : 'bg-sand-50/60 dark:bg-ink-800/30' }}">
                        <div class="flex items-start justify-between gap-3 lg:contents">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-base font-semibold text-ink-900 dark:text-ivory">{{ $row['name'] }}</h3>
                                    @if ($row['locked'])
                                        <span class="badge-neutral"><x-icon name="lock" class="size-3.5" />{{ __('notification_rules_admin.locked') }}</span>
                                    @elseif (! $row['enabled'])
                                        <span class="badge-danger">{{ __('notification_rules_admin.badge_off') }}</span>
                                    @elseif ($row['customized'])
                                        <span class="badge-brass">{{ __('notification_rules_admin.badge_changed') }}</span>
                                    @endif
                                    @if ($row['not_sent'])<span class="badge-neutral" title="{{ __('notification_rules_admin.not_sent_hint') }}">{{ __('notification_rules_admin.badge_not_sent') }}</span>@endif
                                </div>
                                <p class="mt-0.5 text-sm text-ink-500">{{ $row['when'] }}</p>
                                @if ($row['locked'])<p class="mt-1 text-xs text-ink-500">{{ __('notification_rules_admin.locked_hint') }}</p>@endif
                                @if ($row['card_note'])<p class="mt-1 text-xs text-ink-500">{{ __('notification_rules_admin.card.'.$row['key']) }}</p>@endif
                                @if ($row['warnings'] !== [])
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @foreach ($row['warnings'] as $w)<span class="badge-warning !whitespace-normal"><x-icon name="alert" class="size-3.5 shrink-0" />{{ __('notification_rules_admin.warn.'.$w) }}</span>@endforeach
                                    </div>
                                @endif
                                @if ($row['sent'] !== null || $row['template'] || ($canEdit && $row['customized']))
                                    <div class="mt-1 flex flex-wrap items-center gap-x-3">
                                        @if ($row['sent'] !== null)<span class="text-xs tabular-nums text-ink-500" title="{{ __('notification_rules_admin.sent_30_hint') }}">{{ __('notification_rules_admin.sent_30', ['n' => number_format($row['sent'])]) }}</span>@endif
                                        @if ($row['template'])
                                            <a href="{{ route('admin.notification-templates.index', ['event' => $row['event']]) }}" wire:navigate class="btn-ghost btn-sm min-h-11 -ms-3"><x-icon name="edit" class="size-4" />{{ __('notification_rules_admin.edit_message') }}</a>
                                        @endif
                                        @if ($canEdit && $row['customized'])
                                            <button type="button" wire:click="resetRule('{{ $row['event'] }}')" wire:loading.attr="disabled" wire:target="resetRule('{{ $row['event'] }}')" class="btn-ghost btn-sm min-h-11"><x-icon name="refresh" class="size-4" />{{ __('notification_rules_admin.reset_row') }}</button>
                                        @endif
                                    </div>
                                @endif
                            </div>

                            @if ($row['locked'])
                                <div class="shrink-0 lg:col-span-7 lg:text-end"><x-icon name="lock" class="size-5 text-ink-400" /></div>
                            @else
                                <div class="shrink-0 lg:flex lg:justify-center">
                                    <x-admin.switch :on="$row['enabled']" :label="__('notification_rules_admin.master_aria', ['name' => $row['name']])" :disabled="! $canEdit"
                                        wire:click="toggleMaster('{{ $row['event'] }}')" wire:loading.attr="disabled" wire:target="toggleMaster('{{ $row['event'] }}')" />
                                </div>
                            @endif
                        </div>

                        @unless ($row['locked'])
                            <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:contents" @unless ($row['enabled']) title="{{ __('notification_rules_admin.channel_off_master') }}" @endunless>
                                @foreach ($row['channels'] as $ch => $c)
                                    @if ($c['supported'])
                                        <div wire:key="cell-{{ $row['event'] }}-{{ $ch }}" title="{{ $c['ready'] ? $c['hint'] : $c['label'].': '.__('notification_rules_admin.not_set_up') }}"
                                             class="flex min-h-11 items-center justify-between gap-2 rounded-xl border border-sand-200 ps-3 pe-1 dark:border-ink-700 lg:justify-center lg:border-0 lg:p-0 {{ $row['enabled'] && $c['ready'] ? '' : 'opacity-60' }}">
                                            <span class="min-w-0 text-sm text-ink-700 lg:sr-only dark:text-sand-200">{{ $c['label'] }}@unless ($c['ready'])<span class="block text-[0.65rem] leading-tight text-amber-soft">{{ __('notification_rules_admin.not_set_up') }}</span>@endunless</span>
                                            <x-admin.switch :on="$c['on']" :label="__('notification_rules_admin.channel_aria', ['channel' => $c['label'], 'name' => $row['name']])" :disabled="! $canEdit || ! $row['enabled'] || ! $c['ready']"
                                                wire:click="toggleChannel('{{ $row['event'] }}', '{{ $ch }}')" wire:loading.attr="disabled" wire:target="toggleChannel('{{ $row['event'] }}', '{{ $ch }}')" />
                                        </div>
                                    @else
                                        <span class="hidden lg:block" aria-hidden="true"></span>
                                    @endif
                                @endforeach
                            </div>
                        @endunless
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <div class="card mb-6 p-8 text-center">
            <p class="text-ink-500">{{ __('notification_rules_admin.empty') }}</p>
            <button type="button" wire:click="clearFilters" class="btn-secondary mt-4 min-h-11">{{ __('notification_rules_admin.clear') }}</button>
        </div>
    @endforelse

    <section class="card card-pad" aria-labelledby="rules-always-h">
        <h2 id="rules-always-h" class="font-display text-xl font-semibold">{{ __('notification_rules_admin.always_title') }}</h2>
        <p class="mt-1 text-sm text-ink-500">{{ __('notification_rules_admin.always_body') }}</p>
        <ul class="mt-4 grid gap-3 sm:grid-cols-2">
            @foreach ($alwaysSent as $a)
                <li class="rounded-2xl bg-sand-50 p-4 dark:bg-ink-800/60" wire:key="always-{{ $loop->index }}">
                    <p class="font-semibold text-ink-900 dark:text-ivory">{{ $a['name'] }}</p>
                    <p class="mt-0.5 text-sm text-ink-500">{{ $a['hint'] }}</p>
                    @if ($a['url'])<a href="{{ $a['url'] }}" wire:navigate class="btn-ghost btn-sm mt-2 min-h-11 -ms-3" aria-label="{{ $a['name'] }}">{{ __('notification_rules_admin.open') }}<x-icon name="arrow-right" class="size-4 rtl:rotate-180" /></a>@endif
                </li>
            @endforeach
        </ul>
    </section>

    <x-admin.modal name="reset-all-rules" :title="__('notification_rules_admin.reset_all_title')">
        <p class="mb-6 text-sm text-ink-600 dark:text-ink-300">{{ __('notification_rules_admin.reset_all_body') }}</p>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button type="button" class="btn-secondary min-h-11" @click="$dispatch('close-modal', 'reset-all-rules')">{{ __('admin.cancel') }}</button>
            <button type="button" class="btn-primary min-h-11" wire:click="resetAll" @click="$dispatch('close-modal', 'reset-all-rules')">{{ __('notification_rules_admin.reset_all_confirm') }}</button>
        </div>
    </x-admin.modal>
</div>
