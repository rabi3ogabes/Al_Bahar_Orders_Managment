@php
    $st = $lot->status;
    $endsIso = $lot->ends_at?->toIso8601String();
    $minNext = $lot->minNextBid();
@endphp
<div class="mx-auto max-w-7xl" wire:poll.15s="sync">
    <a href="{{ route('admin.auctions.edit', $lot->auction_id) }}" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-ink-500 hover:text-brass-600"><x-icon name="chevron-left" class="size-4 rtl-flip" />{{ $lot->auction->trans('title') }}</a>

    {{-- Realtime subscription (lazy Reverb connection; every event triggers a server resync). --}}
    <div wire:ignore x-data="{ live: false }"
         x-init="
            const e = window.echo && window.echo();
            if (e) {
                const name = 'auction-lot.{{ $lot->id }}';
                const ch = e.channel(name);
                ch.listen('.bid.accepted', () => $wire.sync()).listen('.lot.extended', () => $wire.sync()).listen('.lot.status', () => $wire.sync());
                e.connector?.pusher?.connection?.bind('state_change', (s) => live = s.current === 'connected');
                live = e.connector?.pusher?.connection?.state === 'connected';
                document.addEventListener('livewire:navigating', () => e.leave(name), { once: true });
            }
         " class="mb-4 flex items-center justify-end gap-2 text-xs">
        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1" :class="live ? 'bg-emerald-soft/10 text-emerald-soft' : 'bg-sand-100 text-ink-500 dark:bg-ink-800'">
            <span class="size-1.5 rounded-full bg-current" :class="live && 'animate-pulse'"></span>
            <span x-text="live ? @js(__('auction_admin.realtime_on')) : @js(__('auction_admin.realtime_polling'))"></span>
        </span>
    </div>

    <div class="grid gap-6 lg:grid-cols-3 [&>*]:min-w-0">
        <div class="min-w-0 space-y-6 lg:col-span-2">
            {{-- Hero --}}
            <section class="card overflow-hidden">
                <div class="relative bg-ink-900 p-6 text-ivory sm:p-8">
                    <div class="flex flex-col gap-6 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-xs uppercase tracking-[0.2em] text-brass-300">{{ __('auction_admin.lot_n', ['n' => $lot->lot_number]) }} · <span dir="ltr">{{ $lot->auction->reference }}</span></p>
                            <h1 class="mt-2 font-display text-3xl font-semibold leading-tight sm:text-4xl">{{ $lot->localized('title') }}</h1>
                            <div class="mt-3"><x-admin.status :value="$st" class="!bg-white/10 !text-ivory" /></div>
                        </div>
                        <div class="text-start sm:text-end" wire:key="clock-{{ $lot->last_event_sequence }}-{{ $serverNow->getTimestampMs() }}"
                             x-data="{
                                offset: {{ $serverNow->getTimestampMs() }} - Date.now(),
                                ends: {{ $lot->ends_at ? $lot->ends_at->getTimestampMs() : 'null' }},
                                paused: {{ $st === 'paused' ? (int) $lot->paused_remaining_seconds : 'null' }},
                                now: '', left: '', urgent: false,
                                fmt(s) { s = Math.max(0, Math.floor(s)); const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), x = s % 60; return (h ? h + ':' : '') + String(m).padStart(2, '0') + ':' + String(x).padStart(2, '0'); },
                                tick() {
                                    const t = Date.now() + this.offset;
                                    this.now = new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Qatar', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }).format(t);
                                    const s = this.paused !== null ? this.paused : (this.ends ? (this.ends - t) / 1000 : null);
                                    this.left = s === null ? '—' : this.fmt(s);
                                    this.urgent = s !== null && s <= 60 && this.paused === null;
                                },
                                init() { this.tick(); this.timer = setInterval(() => this.tick(), 250); },
                                destroy() { clearInterval(this.timer); },
                             }">
                            <p class="text-xs uppercase tracking-[0.2em] text-ink-400">{{ in_array($st, ['open', 'paused'], true) ? __('auction_admin.time_left') : __('auction_admin.server_time') }}</p>
                            @if (in_array($st, ['open', 'paused'], true))
                                <p class="font-mono text-4xl font-semibold tabular-nums sm:text-5xl" :class="urgent ? 'text-red-300' : 'text-ivory'" x-text="left" dir="ltr"></p>
                            @endif
                            <p class="mt-1 font-mono text-sm text-ink-300 tabular-nums" dir="ltr"><span x-text="now"></span> <span class="text-ink-500">AST</span></p>
                            @if ($lot->extension_count)<p class="mt-1 text-xs text-brass-300">{{ trans_choice('auction_admin.n_extensions', $lot->extension_count, ['count' => $lot->extension_count]) }}</p>@endif
                        </div>
                    </div>
                    <dl class="mt-8 grid grid-cols-2 gap-4 border-t border-white/10 pt-6 sm:grid-cols-4">
                        <div><dt class="text-xs text-ink-400">{{ __('auction_admin.current_bid') }}</dt><dd class="mt-1 font-display text-2xl font-semibold text-brass-300"><x-admin.money :value="$lot->current_price ?? '0'" /></dd></div>
                        <div><dt class="text-xs text-ink-400">{{ __('auction_admin.next_min') }}</dt><dd class="mt-1 font-display text-2xl font-semibold"><x-admin.money :value="$minNext->toDecimal()" /></dd></div>
                        <div><dt class="text-xs text-ink-400">{{ __('auction_admin.bids') }}</dt><dd class="mt-1 font-display text-2xl font-semibold tabular-nums">{{ $lot->bid_count }}</dd></div>
                        <div><dt class="text-xs text-ink-400">{{ __('auction_admin.reserve') }}</dt>
                            <dd class="mt-1 text-sm">@if ($lot->reserve_price === null)<span class="text-ink-400">{{ __('auction_admin.no_reserve') }}</span>@else<x-admin.money :value="$lot->reserve_price" class="block font-semibold" /><span class="text-xs {{ $reserveMet ? 'text-emerald-300' : 'text-amber-300' }}">{{ $reserveMet ? __('auction_admin.reserve_met') : __('auction_admin.reserve_not_met') }}</span>@endif</dd></div>
                    </dl>
                </div>
                @if ($canEdit)
                    <div class="flex flex-wrap items-center gap-2 p-4 sm:px-6">
                        @if ($st === 'pending')
                            <button type="button" wire:click="ask('open')" class="btn-brass"><x-icon name="play" class="size-4" />{{ __('auction_admin.open_lot') }}</button>
                        @endif
                        @if ($st === 'open')
                            <button type="button" wire:click="ask('pause')" class="btn-secondary"><x-icon name="pause" class="size-4" />{{ __('auction_admin.pause') }}</button>
                        @endif
                        @if ($st === 'paused')
                            <button type="button" wire:click="resume" class="btn-brass"><x-icon name="play" class="size-4" />{{ __('auction_admin.resume') }}</button>
                        @endif
                        @if (in_array($st, ['open', 'paused'], true))
                            <button type="button" wire:click="hammer" wire:confirm="{{ __('auction_admin.confirm_hammer', ['amount' => \App\Support\Money::of($lot->current_price ?? '0')->format()]) }}" class="btn-primary"><x-icon name="gavel" class="size-4" />{{ __('auction_admin.hammer') }}</button>
                        @endif
                        @if (in_array($st, ['pending', 'open', 'paused'], true))
                            <button type="button" wire:click="ask('cancel')" class="btn-ghost ms-auto text-ruby-soft"><x-icon name="ban" class="size-4" />{{ __('auction_admin.cancel_lot') }}</button>
                        @endif
                        @if (! in_array($st, ['pending', 'open', 'paused'], true))
                            <p class="text-sm text-ink-500">{{ __('auction_admin.lot_finished', ['at' => \App\Support\BusinessTime::display($lot->closed_at)]) }}</p>
                        @endif
                    </div>
                    @if ($st === 'open')<p class="border-t border-sand-100 px-4 py-2.5 text-xs text-ink-500 sm:px-6 dark:border-ink-800"><x-icon name="info" class="me-1 inline size-3.5" />{{ __('auction_admin.extend_note', ['window' => $lot->auction->extension_window_seconds, 'by' => $lot->auction->extension_seconds]) }}</p>@endif
                @endif
            </section>

            {{-- Bid feed --}}
            <section class="card overflow-hidden">
                <div class="flex items-center justify-between p-5 sm:p-6">
                    <h2 class="font-display text-xl font-semibold">{{ __('auction_admin.bid_feed') }}</h2>
                    <span class="text-xs text-ink-500">{{ __('auction_admin.feed_hint') }}</span>
                </div>
                @if ($bids->isEmpty())
                    <x-admin.empty :title="__('auction_admin.no_bids')" :description="__('auction_admin.no_bids_hint')" icon="gavel" class="border-t border-sand-100 !py-12 dark:border-ink-800" />
                @else
                    <ol class="max-h-[32rem] divide-y divide-sand-100 overflow-y-auto border-t border-sand-100 dark:divide-ink-800 dark:border-ink-800">
                        @foreach ($bids as $b)
                            @php $lead = $b->id === $lot->current_bid_id; $paddle = $paddles[$b->user_id] ?? null; @endphp
                            <li class="flex items-center gap-3 px-5 py-3 sm:px-6 {{ $lead ? 'bg-brass-50/70 dark:bg-brass-500/10' : '' }} {{ $b->status !== 'accepted' ? 'opacity-50' : '' }}" wire:key="b-{{ $b->id }}">
                                <span class="w-10 shrink-0 font-mono text-xs text-ink-400" dir="ltr">#{{ $b->bid_number }}</span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-ink-900 dark:text-ivory">{{ $b->user?->name }} @if ($paddle)<span class="ms-1 font-mono text-xs text-ink-500">· {{ __('auction_admin.paddle', ['n' => $paddle->paddle_number]) }}</span>@endif
                                        @if ($paddle?->status === 'blocked')<span class="badge-danger ms-1 !text-[0.65rem]">{{ __('auction_admin.blocked') }}</span>@endif</p>
                                    <p class="text-xs text-ink-500"><span dir="ltr">{{ $b->user?->phone }}</span> · {{ $b->type === 'auto' ? __('auction_admin.auto_bid') : __('auction_admin.manual_bid') }} · <span class="tabular-nums">{{ \App\Support\BusinessTime::display($b->placed_at, 'H:i:s') }}</span>
                                        @if ($b->status !== 'accepted') · <span class="text-ruby-soft">{{ __('auction_admin.bid_'.$b->status) }}</span>@endif</p>
                                </div>
                                <x-admin.money :value="$b->amount" class="font-semibold {{ $lead ? 'text-brass-700 dark:text-brass-300' : '' }}" />
                                @if ($canOverride && $b->status === 'accepted' && in_array($st, ['open', 'paused'], true))
                                    <div x-data="{ m: false }" class="relative">
                                        <button type="button" @click="m = !m" @click.outside="m = false" class="btn-ghost btn-sm px-2" aria-label="{{ __('admin.actions') }}"><x-icon name="more" class="size-4" /></button>
                                        <div x-show="m" x-cloak x-transition class="absolute end-0 top-full z-20 mt-1 w-48 rounded-xl border border-sand-200 bg-white p-1 text-sm shadow-[var(--shadow-lift)] dark:border-ink-700 dark:bg-ink-900">
                                            <button type="button" wire:click="ask('retract', '{{ $b->id }}')" class="block w-full rounded-lg px-3 py-2 text-start hover:bg-sand-50 dark:hover:bg-ink-800">{{ __('auction_admin.retract_bid') }}</button>
                                            @if ($paddle && $paddle->status !== 'blocked')<button type="button" wire:click="ask('block', '{{ $b->user_id }}')" class="block w-full rounded-lg px-3 py-2 text-start text-ruby-soft hover:bg-sand-50 dark:hover:bg-ink-800">{{ __('auction_admin.block_bidder') }}</button>@endif
                                        </div>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        </div>

        <aside class="min-w-0 space-y-6">
            <section class="card overflow-hidden">
                <div class="flex items-center justify-between p-5"><h2 class="font-display text-xl font-semibold">{{ __('auction_admin.winners') }}</h2>
                    @if ($canOverride && $overdue)<button type="button" wire:click="processOverdue" wire:confirm="{{ __('auction_admin.confirm_overdue', ['policy' => __('auction_admin.policy_'.$lot->auction->unpaid_winner_policy)]) }}" class="btn-secondary btn-sm text-ruby-soft">{{ __('auction_admin.apply_policy') }}</button>@endif
                </div>
                @if ($winners->isEmpty())
                    <p class="border-t border-sand-100 p-5 text-sm text-ink-500 dark:border-ink-800">{{ __('auction_admin.no_winner_yet') }}</p>
                @else
                    <ul class="divide-y divide-sand-100 border-t border-sand-100 dark:divide-ink-800 dark:border-ink-800">
                        @foreach ($winners as $w)
                            <li class="p-4 text-sm" wire:key="w-{{ $w->id }}">
                                <div class="flex items-center justify-between gap-2"><span class="font-medium">{{ $w->rank > 1 ? __('auction_admin.rank_n', ['n' => $w->rank]) : __('auction_admin.winner') }} · {{ $w->user?->name }}</span><x-admin.status :value="$w->status" /></div>
                                <p class="mt-1 text-xs text-ink-500"><x-admin.money :value="$w->amount" /> · <span dir="ltr">{{ $w->user?->phone }}</span></p>
                                @if ($w->payment_due_at && in_array($w->status, ['pending_payment', 'pending_confirmation'], true))
                                    <p class="mt-1 text-xs {{ $w->payment_due_at->isPast() ? 'text-ruby-soft' : 'text-ink-500' }}">{{ $w->payment_due_at->isPast() ? __('auction_admin.payment_overdue', ['at' => \App\Support\BusinessTime::display($w->payment_due_at)]) : __('auction_admin.payment_due', ['at' => \App\Support\BusinessTime::display($w->payment_due_at)]) }}</p>
                                @endif
                                @if ($w->order_id)<a href="{{ route('admin.orders.show', $w->order_id) }}" wire:navigate class="mt-1 inline-block text-xs font-medium text-brass-600 hover:underline">{{ __('auction_admin.view_order') }}</a>@endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            @if ($canOverride && $autoBids->isNotEmpty())
                <section class="card overflow-hidden">
                    <div class="p-5"><h2 class="font-display text-xl font-semibold">{{ __('auction_admin.proxy_bids') }}</h2><p class="mt-1 text-xs text-ink-500">{{ __('auction_admin.proxy_private') }}</p></div>
                    <ul class="divide-y divide-sand-100 border-t border-sand-100 text-sm dark:divide-ink-800 dark:border-ink-800">
                        @foreach ($autoBids as $ab)<li class="flex justify-between gap-3 px-5 py-3"><span class="truncate">{{ $ab->user?->name }}</span><x-admin.money :value="$ab->max_amount" class="font-medium" /></li>@endforeach
                    </ul>
                </section>
            @endif

            @if ($lot->products->isNotEmpty())
                <section class="card card-pad text-sm">
                    <h2 class="mb-3 font-display text-xl font-semibold">{{ __('auction_admin.linked_stock') }}</h2>
                    @foreach ($lot->products as $p)<div class="flex items-center justify-between gap-3"><x-admin.variant :variant="$p->variant" /><span class="badge-neutral font-mono" dir="ltr">{{ $p->branch?->code }} × {{ $p->quantity }}</span></div>@endforeach
                </section>
            @endif

            <section class="card card-pad">
                <h2 class="mb-4 font-display text-xl font-semibold">{{ __('auction_admin.event_log') }}</h2>
                @if ($events->isEmpty())
                    <p class="text-sm text-ink-500">—</p>
                @else
                    <ol class="space-y-2.5 text-xs">
                        @foreach ($events as $ev)
                            <li class="flex gap-3"><span class="w-8 shrink-0 font-mono text-ink-400" dir="ltr">{{ $ev->sequence }}</span><span class="min-w-0 flex-1 font-medium text-ink-700 dark:text-sand-200">{{ __('auction_admin.ev_'.$ev->type) }}</span><span class="text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($ev->created_at, 'H:i:s') }}</span></li>
                        @endforeach
                    </ol>
                @endif
            </section>
        </aside>
    </div>

    <x-admin.dialog wire:model="showDialog" :title="match ($dialog) { 'open' => __('auction_admin.open_lot'), 'pause' => __('auction_admin.pause'), 'cancel' => __('auction_admin.cancel_lot'), 'retract' => __('auction_admin.retract_bid'), 'block' => __('auction_admin.block_bidder'), default => '' }"
                    :description="match ($dialog) { 'open' => __('auction_admin.open_hint'), 'retract' => __('auction_admin.retract_hint'), 'block' => __('auction_admin.block_hint'), default => null }">
        <form wire:submit="confirmDialog" class="space-y-5">
            @if ($dialog === 'open')
                <x-admin.field :label="__('auction_admin.duration')" for="duration" :hint="__('auction_admin.duration_hint')">
                    <select id="duration" wire:model="duration" class="input">@foreach ([120 => '2', 300 => '5', 600 => '10', 900 => '15', 1800 => '30', 3600 => '60'] as $sec => $min)<option value="{{ $sec }}">{{ trans_choice('auction_admin.n_minutes', (int) $min, ['count' => $min]) }}</option>@endforeach<option value="0">{{ __('auction_admin.use_scheduled_end') }}</option></select>
                </x-admin.field>
            @else
                <x-admin.field :label="__('admin.reason')" for="reason" required><textarea id="reason" wire:model="reason" rows="3" class="input"></textarea></x-admin.field>
            @endif
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary" @click="open = false">{{ __('admin.back') }}</button>
                <button class="{{ $dialog === 'open' ? 'btn-brass' : (in_array($dialog, ['cancel', 'block', 'retract'], true) ? 'btn-danger' : 'btn-primary') }}" wire:loading.attr="disabled" wire:target="confirmDialog">{{ __('admin.confirm') }}</button>
            </div>
        </form>
    </x-admin.dialog>
</div>
