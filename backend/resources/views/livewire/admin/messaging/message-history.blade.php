@php
    $chip = fn (bool $on) => 'inline-flex min-h-11 items-center rounded-full border px-3.5 text-sm font-medium transition sm:min-h-9 '.($on
        ? 'border-brass-500 bg-brass-50 text-ink-900 dark:bg-brass-500/15 dark:text-ivory'
        : 'border-sand-200 bg-white text-ink-600 hover:border-brass-400 hover:bg-sand-50 dark:border-ink-700 dark:bg-transparent dark:text-ink-300 dark:hover:bg-ink-800');
    $kindIcon = ['reminder' => 'cart', 'offer' => 'gift', 'custom' => 'edit'];
    $loading = 'search,filterKind,filterChannel,filterStatus,filterStaff,setWhen,dateFrom,dateTo,clearFilters,showWaiting,showFailed,gotoPage,nextPage,previousPage';
@endphp
<div @if ($live) wire:poll.8s @endif>
    <x-admin.page-header :title="__('nav.customer_messages')" :eyebrow="__('nav.marketing')" :description="__('messaging_history.hint')">
        <x-slot:actions>
            @if ($canSend)<a href="{{ route('admin.customers.index') }}" wire:navigate class="btn-primary"><x-icon name="users" class="size-4" />{{ __('messaging_history.choose_customers') }}</a>@endif
        </x-slot:actions>
    </x-admin.page-header>

    @if ($tiles)
        <div class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-5 [&>*]:min-w-0" role="group" aria-label="{{ __('messaging_history.tiles_label') }}">
            @php
                $tileList = [
                    ['sent', 'megaphone', number_format($tiles['sent']), trans_choice('messaging_history.in_sends', $tiles['sends'], ['count' => number_format($tiles['sends'])]), null],
                    ['delivered', 'check', $tiles['delivered_pct'] === null ? '—' : $tiles['delivered_pct'].'%', $tiles['wa_sent'] > 0 ? __('messaging_history.of_whatsapp', ['delivered' => number_format($tiles['wa_delivered']), 'sent' => number_format($tiles['wa_sent'])]) : __('messaging_history.no_whatsapp_yet'), null],
                    ['failed', 'alert', number_format($tiles['failed']), $tiles['failed'] > 0 ? '' : __('messaging_history.none_failed'), $tiles['failed'] > 0 ? 'showFailed' : null],
                    ['skipped', 'ban', number_format($tiles['skipped']), __('messaging_history.skipped_hint'), null],
                    ['waiting', 'clock', number_format($tiles['waiting']), $tiles['waiting'] > 0 ? trans_choice('messaging_history.in_sends', $tiles['waiting_sends'], ['count' => number_format($tiles['waiting_sends'])]) : __('messaging_history.none_waiting'), $tiles['waiting'] > 0 ? 'showWaiting' : null],
                ];
            @endphp
            @foreach ($tileList as [$key, $icon, $value, $hint, $action])
                <div @class(['card flex flex-col gap-1 p-4', 'col-span-2 lg:col-span-1' => $loop->last]) wire:key="tile-{{ $key }}">
                    <div class="flex items-start justify-between gap-2">
                        <p class="text-xs font-medium text-ink-500 dark:text-ink-400">{{ __('messaging_history.tile.'.$key) }}</p>
                        <x-icon :name="$icon" :class="\Illuminate\Support\Arr::toCssClasses(['size-4 shrink-0', 'text-ruby-soft' => $key === 'failed' && $tiles['failed'] > 0, 'text-amber-soft' => $key === 'waiting' && $tiles['waiting'] > 0, 'text-brass-500' => ! ($key === 'failed' && $tiles['failed'] > 0) && ! ($key === 'waiting' && $tiles['waiting'] > 0)])" />
                    </div>
                    <p class="font-display text-2xl font-semibold tracking-tight text-ink-900 tabular-nums dark:text-ivory">{{ $value }}</p>
                    <p class="flex flex-wrap items-center gap-x-2 text-[0.7rem] leading-snug text-ink-500 dark:text-ink-400">
                        @if ($hint !== '')<span>{{ $hint }}</span>@endif
                        @if ($action)<button type="button" wire:click="{{ $action }}" class="font-medium text-brass-600 underline-offset-2 hover:underline dark:text-brass-300">{{ __('messaging_history.show_these') }}</button>@endif
                    </p>
                </div>
            @endforeach
        </div>
    @endif

    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('messaging_history.search')" />
            <div class="grid grid-cols-2 gap-2 sm:contents">
                <select wire:model.live="filterKind" class="input sm:w-44" aria-label="{{ __('messaging_history.filter_kind') }}">
                    <option value="">{{ __('messaging_history.all_kinds') }}</option>
                    @foreach (\App\Domain\Messaging\Models\MessageBatch::KINDS as $k)<option value="{{ $k }}">{{ __('messaging_admin.kind.'.$k) }}</option>@endforeach
                </select>
                <select wire:model.live="filterChannel" class="input sm:w-44" aria-label="{{ __('messaging_history.filter_channel') }}">
                    <option value="">{{ __('messaging_history.all_channels') }}</option>
                    @foreach (\App\Domain\Messaging\Models\MessageBatch::CHANNELS as $c)<option value="{{ $c }}">{{ __('messaging_admin.channel.'.$c) }}</option>@endforeach
                </select>
                <select wire:model.live="filterStatus" class="input sm:w-48" aria-label="{{ __('messaging_history.filter_status') }}">
                    <option value="">{{ __('admin.all_statuses') }}</option>
                    <optgroup label="{{ __('messaging_history.group_send') }}">
                        @foreach (['scheduled', 'sending', 'done', 'cancelled'] as $st)<option value="{{ $st }}">{{ __('messaging_admin.batch_status.'.$st) }}</option>@endforeach
                    </optgroup>
                    <optgroup label="{{ __('messaging_history.group_contains') }}">
                        @foreach (['has_failed', 'has_skipped', 'has_delivered'] as $st)<option value="{{ $st }}">{{ __('messaging_history.status_'.$st) }}</option>@endforeach
                    </optgroup>
                </select>
                @if ($senders->isNotEmpty())
                    <select wire:model.live="filterStaff" class="input sm:w-52" aria-label="{{ __('messaging_history.filter_staff') }}">
                        <option value="">{{ __('messaging_history.all_staff') }}</option>
                        @foreach ($senders as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
                    </select>
                @endif
            </div>
            <x-slot:meta>@if ($batches){{ trans_choice('messaging_history.sends_count', $batches->total(), ['count' => number_format($batches->total())]) }}@endif</x-slot:meta>
        </x-admin.toolbar>

        <div class="space-y-3 border-b border-sand-100 px-4 py-4 sm:px-6 dark:border-ink-800">
            <div class="flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('messaging_history.when') }}">
                <span class="me-1 w-full text-xs font-semibold uppercase tracking-wider text-ink-500 sm:w-auto sm:min-w-28 rtl:tracking-normal dark:text-ink-400">{{ __('messaging_history.when') }}</span>
                @foreach (['all', 'today', '7d', '30d', '90d', 'custom'] as $w)
                    <button type="button" wire:click="setWhen('{{ $w }}')" aria-pressed="{{ $when === $w ? 'true' : 'false' }}" class="{{ $chip($when === $w) }}">@if ($w === 'custom')<x-icon name="calendar" class="me-1.5 size-4" />@endif{{ __('messaging_history.when_'.$w) }}</button>
                @endforeach
            </div>
            <div class="flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('messaging_history.older_label') }}">
                <span class="me-1 w-full text-xs font-semibold uppercase tracking-wider text-ink-500 sm:w-auto sm:min-w-28 rtl:tracking-normal dark:text-ink-400">{{ __('messaging_history.older_label') }}</span>
                @foreach (['1d', '7d', '30d', '90d'] as $o)
                    <button type="button" wire:click="setWhen('older_{{ $o }}')" aria-pressed="{{ $when === 'older_'.$o ? 'true' : 'false' }}" aria-label="{{ __('messaging_history.older_aria.'.$o) }}" class="{{ $chip($when === 'older_'.$o) }}">{{ __('messaging_history.older_'.$o) }}</button>
                @endforeach
            </div>
            @if ($when === 'custom')
                <div class="rounded-2xl bg-sand-50 p-4 dark:bg-ink-800/50">
                    <div class="grid gap-3 sm:max-w-lg sm:grid-cols-2 [&>*]:min-w-0">
                        <x-admin.field :label="__('messaging_history.from')" for="dateFrom">
                            <input id="dateFrom" type="date" wire:model.live="dateFrom" max="{{ $dateTo ?: '' }}" class="input tabular-nums" dir="ltr">
                        </x-admin.field>
                        <x-admin.field :label="__('messaging_history.to')" for="dateTo">
                            <input id="dateTo" type="date" wire:model.live="dateTo" min="{{ $dateFrom ?: '' }}" class="input tabular-nums" dir="ltr">
                        </x-admin.field>
                    </div>
                    <p class="field-hint">{{ __('messaging_history.custom_hint') }}</p>
                </div>
            @endif
            <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-500 dark:text-ink-400"><x-icon name="clock" class="size-3.5" /><span>{{ $this->periodSummary() }}</span>
                @if ($filtersActive)<button type="button" wire:click="clearFilters" class="font-medium text-brass-600 underline-offset-2 hover:underline dark:text-brass-300">{{ __('messaging_history.clear') }}</button>@endif
            </p>
        </div>

        <div wire:loading.delay.class="opacity-50" wire:target="{{ $loading }}" class="transition-opacity">
            @if ($failed)
                <x-admin.empty :title="__('messaging_history.error_title')" :description="__('messaging_history.error_hint')" icon="alert">
                    <button type="button" wire:click="$refresh" class="btn-secondary min-h-11"><x-icon name="refresh" class="size-4" />{{ __('messaging_history.try_again') }}</button>
                </x-admin.empty>
            @elseif ($batches->isEmpty() && ! $hasAny)
                <x-admin.empty :title="__('messaging_history.empty_title')" :description="__('messaging_history.empty_hint')" icon="megaphone">
                    @if ($canSend)<a href="{{ route('admin.customers.index') }}" wire:navigate class="btn-primary min-h-11"><x-icon name="users" class="size-4" />{{ __('messaging_history.choose_customers') }}</a>@endif
                </x-admin.empty>
            @elseif ($batches->isEmpty())
                <x-admin.empty :title="__('messaging_history.none_title')" :description="__('messaging_history.none_hint')" icon="search">
                    <button type="button" wire:click="clearFilters" class="btn-secondary min-h-11"><x-icon name="refresh" class="size-4" />{{ __('messaging_history.show_everything') }}</button>
                </x-admin.empty>
            @else
                <ul class="divide-y divide-sand-100 dark:divide-ink-800" aria-label="{{ __('nav.customer_messages') }}">
                    @foreach ($batches as $b)
                        @php
                            $at = $this->clockTime($b);
                            $due = $b->status === 'scheduled' && $b->scheduled_at && $b->scheduled_at->lte(now());
                        @endphp
                        <li wire:key="b-{{ $b->id }}" wire:click="openView('{{ $b->id }}')" class="cursor-pointer transition hover:bg-sand-50/60 dark:hover:bg-ink-800/40">
                            <div class="grid gap-3 px-4 py-4 sm:px-6 lg:grid-cols-[minmax(0,1fr)_17rem_1.25rem] lg:items-center lg:gap-6">
                                <div class="flex min-w-0 items-start gap-3">
                                    <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-brass-50 text-brass-600 dark:bg-brass-500/15 dark:text-brass-300" aria-hidden="true"><x-icon :name="$kindIcon[$b->kind] ?? 'megaphone'" class="size-5" /></span>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                            <a href="{{ route('admin.messages.index', ['view' => $b->id]) }}" wire:click.prevent.stop="openView('{{ $b->id }}')" class="min-w-0 max-w-full truncate font-medium text-ink-900 hover:text-brass-600 dark:text-ivory" title="{{ $b->template_name }}">{{ $b->template_name }}</a>
                                            @unless ($this->isPlainCustom($b))<span class="badge-brass">{{ __('messaging_admin.kind.'.$b->kind) }}</span>@endunless
                                            <x-admin.messaging.status :value="$b->status" scope="batch" />
                                        </div>
                                        <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-ink-600 dark:text-ink-300">
                                            <span class="inline-flex min-w-0 items-center gap-1.5"><x-icon :name="$b->source === 'single' ? 'user' : 'users'" class="size-3.5 shrink-0 text-ink-400" /><span class="truncate">{{ $this->audienceLabel($b) }}</span></span>
                                            @foreach ($b->channels as $ch)<x-admin.messaging.channel :channel="$ch" />@endforeach
                                        </p>
                                        <p class="mt-1 flex flex-wrap items-center gap-x-1.5 text-xs text-ink-500 dark:text-ink-400">
                                            @if ($b->status === 'scheduled')<span>{{ $due ? __('messaging_history.due_now') : __('messaging_history.goes_out') }}</span>@endif
                                            <x-admin.messaging.ago :at="$at" class="font-medium text-ink-700 dark:text-sand-200" />
                                            <span aria-hidden="true">·</span><span class="tabular-nums">{{ \App\Support\BusinessTime::display($at) }}</span>
                                            <span aria-hidden="true">·</span><span>{{ __('messaging_history.by', ['name' => $b->creator?->name ?? __('messaging_history.former_staff')]) }}</span>
                                        </p>
                                    </div>
                                </div>
                                <div class="min-w-0 ps-14 lg:ps-0">
                                    @if ($b->status === 'scheduled')
                                        <p class="text-sm text-ink-500 dark:text-ink-400">{{ trans_choice('messaging_history.waiting_for_time', (int) $b->recipients_count, ['count' => number_format($b->recipients_count)]) }}</p>
                                    @else
                                        <x-admin.messaging.result :sent="$b->sent_count" :delivered="$b->delivered_count" :failed="$b->failed_count" :skipped="$b->skipped_count" :queued="$b->queued_count" />
                                    @endif
                                </div>
                                <x-icon name="chevron-right" class="hidden size-4 text-ink-400 rtl-flip lg:block" />
                            </div>
                        </li>
                    @endforeach
                </ul>
                @if ($batches->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $batches->links('components.admin.pagination') }}</div>@endif
            @endif
        </div>
    </div>

    @include('livewire.admin.messaging.partials.history-window')
</div>
