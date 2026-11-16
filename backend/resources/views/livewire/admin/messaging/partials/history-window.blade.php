{{-- One send in a side window (?view=<id>): summary, the texts as sent, and one row per customer. Include once, inside the list's root element. --}}
@php
    $b = $this->batch;
    $counts = $b ? $this->statusCounts : [];
    $retryable = $canSend && $b && $b->failed_count > 0 && in_array($b->status, ['done', 'sending'], true);
    $cancellable = $canSend && $b && in_array($b->status, ['scheduled', 'sending'], true);
    $content = $b->content ?? [];
    $coupon = $content['coupon'] ?? [];
    $at = $b ? $this->clockTime($b) : null;
    $rows = $b ? $this->recipients : null;
    $statusFilters = collect(['delivered', 'sent', 'failed', 'skipped', 'queued', 'cancelled'])->filter(fn ($s) => ($counts[$s] ?? 0) > 0);
    $totalRows = array_sum($counts);
    $chip = fn (bool $on) => 'inline-flex min-h-11 items-center gap-1.5 rounded-full border px-3 text-sm font-medium transition sm:min-h-9 '.($on
        ? 'border-brass-500 bg-brass-50 text-ink-900 dark:bg-brass-500/15 dark:text-ivory'
        : 'border-sand-200 bg-white text-ink-600 hover:border-brass-400 hover:bg-sand-50 dark:border-ink-700 dark:bg-transparent dark:text-ink-300 dark:hover:bg-ink-800');
    $label = 'text-xs font-medium text-ink-500 dark:text-ink-400';
@endphp
<x-admin.dialog wire:model="showView" :title="$b?->template_name" :description="$b ? __('messaging_history.by', ['name' => $b->creator?->name ?? __('messaging_history.former_staff')]).' · '.\App\Support\BusinessTime::display($at) : null" max-width="max-w-3xl">
    @if ($b)
        <x-admin.drawer-bar>
            <x-admin.messaging.status :value="$b->status" scope="batch" />
            <span class="inline-flex items-center gap-1.5"><x-icon name="clock" class="size-3.5" /><span>@if ($b->status === 'scheduled'){{ __('messaging_history.goes_out') }} @endif<x-admin.messaging.ago :at="$at" /></span></span>
        </x-admin.drawer-bar>

        @if ($retryable || $cancellable)
            <div class="mb-5 flex flex-wrap gap-2">
                @if ($retryable)<button type="button" wire:click="askRetry" class="btn-secondary min-h-11"><x-icon name="refresh" class="size-4" />{{ trans_choice('messaging_history.retry_failed', $b->failed_count, ['count' => number_format($b->failed_count)]) }}</button>@endif
                @if ($cancellable)<button type="button" wire:click="askCancel" class="btn-secondary min-h-11 text-ruby-soft"><x-icon name="ban" class="size-4" />{{ __('messaging_history.cancel_send') }}</button>@endif
            </div>
        @endif

        {{-- Result --}}
        <section class="mb-6 outline-none" aria-label="{{ __('messaging_history.result') }}" tabindex="-1" autofocus>
            @php
                $stat = collect([['sent', $b->sent_count], ['delivered', $b->delivered_count], ['failed', $b->failed_count], ['skipped', $b->skipped_count], ['queued', $counts['queued'] ?? 0]])
                    ->reject(fn ($t) => $t[0] === 'queued' && $t[1] === 0 && ! in_array($b->status, ['scheduled', 'sending'], true));
            @endphp
            <div @class(['grid grid-cols-2 gap-3 [&>*]:min-w-0', 'sm:grid-cols-5' => $stat->count() === 5, 'sm:grid-cols-4' => $stat->count() < 5])>
                @foreach ($stat as [$k, $n])
                    <div class="rounded-2xl border border-sand-200/70 p-3 dark:border-ink-700">
                        <p class="{{ $label }}">{{ __('messaging_history.stat.'.$k) }}</p>
                        <p @class(['mt-1 font-display text-2xl font-semibold tabular-nums', 'text-ruby-soft' => $k === 'failed' && $n > 0, 'text-ink-900 dark:text-ivory' => ! ($k === 'failed' && $n > 0)])>{{ number_format($n) }}</p>
                    </div>
                @endforeach
            </div>
            <div class="mt-4">
                @if ($b->status === 'scheduled')
                    <x-admin.messaging.result :queued="$totalRows" :legend="false" />
                    <p class="mt-2 text-xs text-ink-500 dark:text-ink-400">{{ __('messaging_history.waiting_explain', ['at' => \App\Support\BusinessTime::display($b->scheduled_at)]) }}</p>
                @else
                    <x-admin.messaging.result :sent="$b->sent_count" :delivered="$b->delivered_count" :failed="$b->failed_count" :skipped="$b->skipped_count" :queued="$b->queued_count" />
                @endif
            </div>
        </section>

        {{-- Summary --}}
        <section class="mb-6 rounded-2xl border border-sand-200/70 p-4 sm:p-5 dark:border-ink-700" aria-label="{{ __('messaging_history.summary') }}">
            <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2 [&>div]:min-w-0">
                <div>
                    <dt class="{{ $label }}">{{ __('messaging_history.sent_by') }}</dt>
                    <dd class="mt-0.5 font-medium text-ink-900 dark:text-ivory">{{ $b->creator?->name ?? __('messaging_history.former_staff') }}</dd>
                </div>
                <div>
                    <dt class="{{ $label }}">{{ __('messaging_history.template') }}</dt>
                    <dd class="mt-0.5 flex flex-wrap items-center gap-2"><span class="font-medium text-ink-900 dark:text-ivory">{{ $b->template_name }}</span>@unless ($this->isPlainCustom($b))<span class="badge-brass">{{ __('messaging_admin.kind.'.$b->kind) }}</span>@endunless</dd>
                    @if ($this->templateRemoved($b))<dd class="mt-1 text-xs text-ink-500 dark:text-ink-400">{{ __('messaging_history.template_removed') }}</dd>@endif
                </div>
                <div>
                    <dt class="{{ $label }}">{{ __('messaging_history.how') }}</dt>
                    <dd class="mt-0.5 flex flex-wrap items-center gap-x-4 gap-y-1">@foreach ($b->channels as $ch)<x-admin.messaging.channel :channel="$ch" class="!text-sm" />@endforeach</dd>
                </div>
                <div>
                    <dt class="{{ $label }}">{{ __('messaging_history.went_to') }}</dt>
                    <dd class="mt-0.5 text-ink-900 dark:text-ivory">{{ $this->audienceLabel($b, lookup: true) }}</dd>
                    @if (($b->audience['override_cooldown'] ?? false))<dd class="mt-1 text-xs text-ink-500 dark:text-ink-400">{{ __('messaging_history.sent_anyway') }}</dd>@endif
                </div>
                <div class="sm:col-span-2">
                    <dt class="{{ $label }}">{{ __('messaging_history.times') }}</dt>
                    <dd class="mt-0.5 flex flex-wrap gap-x-5 gap-y-1 tabular-nums text-ink-700 dark:text-sand-200">
                        <span>{{ __('messaging_history.t_created') }} <b class="font-medium">{{ \App\Support\BusinessTime::display($b->created_at) }}</b></span>
                        @if ($b->scheduled_at)<span>{{ __('messaging_history.t_scheduled') }} <b class="font-medium">{{ \App\Support\BusinessTime::display($b->scheduled_at) }}</b></span>@endif
                        @if ($b->started_at)<span>{{ __('messaging_history.t_started') }} <b class="font-medium">{{ \App\Support\BusinessTime::display($b->started_at) }}</b></span>@endif
                        @if ($b->completed_at)<span>{{ $b->status === 'cancelled' ? __('messaging_history.t_cancelled') : __('messaging_history.t_finished') }} <b class="font-medium">{{ \App\Support\BusinessTime::display($b->completed_at) }}</b></span>@endif
                    </dd>
                </div>
                @if ($coupon['enabled'] ?? false)
                    <div class="sm:col-span-2">
                        <dt class="{{ $label }}">{{ __('messaging_history.coupon') }}</dt>
                        <dd class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span class="badge-brass font-mono" dir="ltr">{{ $coupon['prefix'] ?? '' }}-…</span>
                            <span>{{ trans_choice('messaging_history.coupon_valid', (int) ($coupon['valid_hours'] ?? 0), ['count' => number_format((int) ($coupon['valid_hours'] ?? 0))]) }}</span>
                            @if ($this->promotionName)<span class="text-ink-500">{{ __('messaging_history.coupon_offer', ['name' => $this->promotionName]) }}</span>@endif
                        </dd>
                    </div>
                @endif
                @if (! empty($content['deep_link']))
                    <div class="sm:col-span-2">
                        <dt class="{{ $label }}">{{ __('messaging_admin.field.deep_link') }}</dt>
                        <dd class="mt-0.5 break-all font-mono text-xs text-ink-700 dark:text-sand-200" dir="ltr">{{ $content['deep_link'] }}</dd>
                    </div>
                @endif
            </dl>
        </section>

        {{-- The texts as sent --}}
        <section class="mb-6" aria-label="{{ __('messaging_history.texts') }}">
            <h3 class="mb-1 font-display text-lg font-semibold text-ink-900 dark:text-ivory">{{ __('messaging_history.texts') }}</h3>
            <p class="mb-3 text-xs text-ink-500 dark:text-ink-400">{{ __('messaging_history.texts_hint') }}</p>
            <div class="space-y-3">
                @foreach ($b->channels as $ch)
                    @php
                        $pairs = $ch === 'whatsapp'
                            ? collect(['en', 'ar'])->mapWithKeys(fn ($l) => [$l => trim((string) ($content['whatsapp_'.$l] ?? '')) !== '' ? [null, $content['whatsapp_'.$l]] : [$content['title_'.$l] ?? '', $content['body_'.$l] ?? '']])
                            : collect(['en', 'ar'])->mapWithKeys(fn ($l) => [$l => [$content['title_'.$l] ?? '', $content['body_'.$l] ?? '']]);
                        $fallback = $ch === 'whatsapp' && collect(['en', 'ar'])->contains(fn ($l) => trim((string) ($content['whatsapp_'.$l] ?? '')) === '');
                    @endphp
                    <div class="rounded-2xl border border-sand-200/70 p-4 dark:border-ink-700" wire:key="txt-{{ $ch }}">
                        <p class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1"><x-admin.messaging.channel :channel="$ch" class="!text-sm !font-semibold" />@if ($fallback)<span class="text-xs text-ink-500 dark:text-ink-400">{{ __('messaging_history.whatsapp_fallback') }}</span>@endif</p>
                        <div class="grid gap-3 sm:grid-cols-2 [&>*]:min-w-0">
                            @foreach ($pairs as $l => [$title, $body])
                                <div class="rounded-xl bg-sand-50 p-3 dark:bg-ink-800/60" dir="{{ $l === 'ar' ? 'rtl' : 'ltr' }}" lang="{{ $l }}">
                                    <p class="mb-1.5 text-[0.7rem] font-semibold uppercase tracking-wider text-ink-500 dark:text-ink-400 {{ $l === 'ar' ? 'tracking-normal' : '' }}">{{ $l === 'ar' ? 'العربية' : 'English' }}</p>
                                    @if ($title !== null && trim((string) $title) !== '')<p class="text-sm font-semibold text-ink-900 dark:text-ivory"><x-admin.messaging.text :value="$title" /></p>@endif
                                    @if (trim((string) $body) !== '')<p class="mt-0.5 text-sm text-ink-700 dark:text-sand-200"><x-admin.messaging.text :value="$body" /></p>
                                    @elseif ($title === null || trim((string) $title) === '')<p class="text-sm text-ink-400">—</p>@endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- One row per customer and channel --}}
        <section class="mb-2" id="msg-recipients" aria-label="{{ __('messaging_history.customers_title') }}">
            <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                <h3 class="font-display text-lg font-semibold text-ink-900 dark:text-ivory">{{ __('messaging_history.customers_title') }} <span class="text-sm font-normal text-ink-500 tabular-nums">({{ number_format($totalRows) }})</span></h3>
                <label class="relative block w-full sm:max-w-56">
                    <span class="sr-only">{{ __('messaging_history.find_customer') }}</span>
                    <x-icon name="search" class="pointer-events-none absolute top-1/2 start-3 size-4 -translate-y-1/2 text-ink-400" />
                    <input type="search" wire:model.live.debounce.350ms="recipientSearch" placeholder="{{ __('messaging_history.find_customer') }}" class="input ps-9" data-no-dirty>
                </label>
            </div>
            @if ($statusFilters->isNotEmpty())
                <div class="mb-3 flex flex-wrap gap-2" role="group" aria-label="{{ __('messaging_history.filter_status') }}">
                    <button type="button" wire:click="setRecipientStatus('')" aria-pressed="{{ $recipientStatus === '' ? 'true' : 'false' }}" class="{{ $chip($recipientStatus === '') }}">{{ __('messaging_history.all') }} <span class="text-xs tabular-nums text-ink-500">{{ number_format($totalRows) }}</span></button>
                    @foreach ($statusFilters as $s)
                        <button type="button" wire:click="setRecipientStatus('{{ $s }}')" aria-pressed="{{ $recipientStatus === $s ? 'true' : 'false' }}" class="{{ $chip($recipientStatus === $s) }}">{{ __('messaging_admin.status.'.$s) }} <span class="text-xs tabular-nums text-ink-500">{{ number_format($counts[$s]) }}</span></button>
                    @endforeach
                </div>
            @endif

            @if (! $canOpenCustomer)
                <p class="rounded-2xl bg-sand-50 p-4 text-sm text-ink-600 dark:bg-ink-800/50 dark:text-ink-300">{{ __('messaging_history.no_customer_access') }}</p>
            @else
                <div wire:loading wire:target="setRecipientStatus,recipientSearch,gotoPage,nextPage,previousPage" class="divide-y divide-sand-100 dark:divide-ink-800" aria-hidden="true">
                    @foreach (range(1, 4) as $i)<div class="flex items-center justify-between gap-4 py-3"><div class="space-y-2"><div class="skeleton h-4 w-36"></div><div class="skeleton h-3 w-24"></div></div><div class="skeleton h-6 w-20 rounded-full"></div></div>@endforeach
                </div>
                <div wire:loading.remove wire:target="setRecipientStatus,recipientSearch,gotoPage,nextPage,previousPage">
                    @if ($rows->isEmpty())
                        <x-admin.empty :title="$totalRows === 0 ? __('messaging_history.no_rows') : __('messaging_history.no_rows_filtered')" :description="$totalRows === 0 ? __('messaging_history.no_rows_hint') : null" icon="users" class="!py-10" />
                    @else
                        <ul class="divide-y divide-sand-100 dark:divide-ink-800">
                            @foreach ($rows as $m)
                                <li wire:key="r-{{ $m->id }}" x-data="{ text: false }" class="py-3">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            @if ($canOpenCustomer)
                                                <a href="{{ route('admin.customers.show', $m->user_id) }}" wire:navigate class="font-medium text-ink-900 hover:text-brass-600 dark:text-ivory">{{ $m->customer_name }}</a>
                                            @else
                                                <span class="font-medium text-ink-900 dark:text-ivory">{{ $m->customer_name }}</span>
                                            @endif
                                            @if ($m->customer_phone)<span class="block text-xs text-ink-500 tabular-nums" dir="ltr">{{ $reveal ? $m->customer_phone : \App\Support\Phone::mask($m->customer_phone) }}</span>@endif
                                        </div>
                                        <div class="flex shrink-0 flex-wrap items-center justify-end gap-1.5">
                                            <x-admin.messaging.channel :channel="$m->channel" class="rounded-full bg-sand-100 px-2 py-0.5 dark:bg-ink-800" />
                                            <x-admin.messaging.status :value="$m->status" />
                                        </div>
                                    </div>
                                    <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink-500 dark:text-ink-400">
                                        @if ($m->reason)<span class="font-medium {{ in_array($m->status, ['failed'], true) ? 'text-ruby-soft' : '' }}"><x-admin.messaging.reason :reason="$m->reason" /></span>@endif
                                        @if ($m->delivered_at)<span class="tabular-nums" title="{{ \App\Support\BusinessTime::display($m->delivered_at, 'D d M Y, H:i') }}">{{ __('messaging_history.delivered_at', ['at' => \App\Support\BusinessTime::display($m->delivered_at)]) }}</span>
                                        @elseif ($m->sent_at)<span class="tabular-nums">{{ __('messaging_history.sent_at', ['at' => \App\Support\BusinessTime::display($m->sent_at)]) }}</span>@endif
                                        @if ($m->channel === 'notification' && in_array($m->status, ['sent', 'delivered'], true))<x-admin.messaging.phones-note :note="\App\Domain\Notifications\Services\DevicePushReach::deliveryNote($m->meta)" />@endif
                                        @if ($m->coupon_code)<span class="badge-brass font-mono" dir="ltr" title="{{ __('messaging_history.coupon') }}">{{ $m->coupon_code }}</span>@endif
                                        @if ($m->body)<button type="button" @click="text = ! text" :aria-expanded="text" class="font-medium text-brass-600 underline-offset-2 hover:underline dark:text-brass-300"><span x-show="! text">{{ __('messaging_history.show_text') }}</span><span x-show="text" x-cloak>{{ __('messaging_history.hide_text') }}</span></button>@endif
                                    </p>
                                    @if ($m->body)
                                        <div x-show="text" x-cloak class="mt-2 rounded-xl bg-sand-50 p-3 text-sm text-ink-700 dark:bg-ink-800/60 dark:text-sand-200" dir="{{ $m->locale === 'ar' ? 'rtl' : 'ltr' }}" lang="{{ $m->locale }}">
                                            @if ($m->title)<p class="font-semibold text-ink-900 dark:text-ivory">{{ $m->title }}</p>@endif
                                            <p class="whitespace-pre-line break-words">{{ $m->body }}</p>
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @if ($rows->hasPages())
                            <nav class="mt-3 flex items-center justify-between gap-3 border-t border-sand-100 pt-3 dark:border-ink-800" aria-label="{{ __('org_admin.page_nav') }}">
                                <p class="text-xs tabular-nums text-ink-500">{{ $rows->firstItem() }}–{{ $rows->lastItem() }} / {{ $rows->total() }}</p>
                                <div class="flex items-center gap-1">
                                    <button type="button" wire:click="previousPage('rp')" @disabled($rows->onFirstPage()) class="btn-ghost size-11 p-0" aria-label="{{ __('org_admin.prev_page') }}" x-on:click="document.getElementById('msg-recipients')?.scrollIntoView({ block: 'start', behavior: 'smooth' })"><x-icon name="chevron-left" class="size-4 rtl-flip" /></button>
                                    <span class="px-1 text-xs tabular-nums text-ink-500">{{ $rows->currentPage() }} / {{ $rows->lastPage() }}</span>
                                    <button type="button" wire:click="nextPage('rp')" @disabled(! $rows->hasMorePages()) class="btn-ghost size-11 p-0" aria-label="{{ __('org_admin.next_page') }}" x-on:click="document.getElementById('msg-recipients')?.scrollIntoView({ block: 'start', behavior: 'smooth' })"><x-icon name="chevron-right" class="size-4 rtl-flip" /></button>
                                </div>
                            </nav>
                        @endif
                    @endif
                </div>
            @endif
        </section>
    @endif
</x-admin.dialog>

{{-- The decision: asks before putting failed messages back in the queue or stopping a send. Declared after the window so it lands on top. --}}
<x-admin.dialog variant="modal" wire:model="showConfirm" max-width="max-w-md"
    :title="$confirming === 'cancel' ? __('messaging_history.q_cancel') : __('messaging_history.q_retry')"
    :description="$confirming === 'cancel' ? __('messaging_history.q_cancel_hint', ['count' => number_format($b ? ($counts['queued'] ?? 0) : 0)]) : __('messaging_history.q_retry_hint', ['count' => number_format($b?->failed_count ?? 0)])">
    @if ($confirming !== '')
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button type="button" class="btn-secondary min-h-11" data-cancel @click="open = false">{{ $confirming === 'cancel' ? __('messaging_history.keep_sending') : __('messaging_history.not_now') }}</button>
            @if ($confirming === 'cancel')
                <button type="button" wire:click="confirm" wire:loading.attr="disabled" wire:target="confirm" class="btn-danger min-h-11"><x-icon name="ban" class="size-4" />{{ __('messaging_history.cancel_send') }}</button>
            @else
                <button type="button" wire:click="confirm" wire:loading.attr="disabled" wire:target="confirm" class="btn-primary min-h-11"><x-icon name="refresh" class="size-4" />{{ trans_choice('messaging_history.retry_failed', $b?->failed_count ?? 0, ['count' => number_format($b?->failed_count ?? 0)]) }}</button>
            @endif
        </div>
    @endif
</x-admin.dialog>
