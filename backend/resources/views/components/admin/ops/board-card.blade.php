@props(['card', 'wall' => false])
{{--
    One card of the live board: one branch part of an order. What it is waiting for in words, the order clock as a chip (shape + word + colour, never colour
    alone), the facts the branch needs to act (items, slot, cash to collect, driver) and at most two buttons: Open (the side window of the page) and the
    one next step this person may take. No customer name, phone or address. In the wall display (a screen for everyone to read) there are no buttons.
    The wrapper carries data-card / data-lane so resources/js/ops-board.js can welcome a new card.
--}}
@php
    $state = $card->state();
    $edge = match ($state) { 'red' => 'border-s-ruby-soft', 'amber' => 'border-s-amber-soft', default => 'border-s-sand-300 dark:border-s-ink-600' };
    $big = $wall;
    $lane = fn (string $key) => __('ops_board.lane.'.$key.'.title');
    $chipTitle = $card->age ? __('ops_live.chip.title', ['state' => __('ops_live.state.'.$card->age->state), 'age' => $card->age->label]).($card->age->paused ? ' · '.__('ops_live.chip.paused') : '') : null;
    $pay = match (true) {
        $card->payment === 'cod' => $card->due ? __('ops_board.pay.cod_amount', ['amount' => $card->due]) : __('ops_board.pay.cod'),
        $card->payment === 'pay_at_pickup' => $card->due ? __('ops_board.pay.pay_at_pickup_amount', ['amount' => $card->due]) : __('ops_board.pay.pay_at_pickup'),
        default => __('ops_board.pay.sadad'),
    };
    $cash = $card->due !== null;
@endphp
<article wire:key="card-{{ $card->id }}" data-card="{{ $card->id }}" data-lane="{{ $card->lane }}" data-state="{{ $state }}" aria-label="{{ $card->number }} · {{ __('ops_board.stage.'.$card->stage) }}"
         class="rounded-xl border border-s-4 border-sand-200/80 bg-white p-3 shadow-sm transition data-[fresh]:ring-2 data-[fresh]:ring-brass-400 dark:border-ink-700 dark:bg-ink-900 {{ $edge }} {{ $big ? 'p-4' : '' }}">
    <div class="flex flex-wrap items-start justify-between gap-x-2 gap-y-1">
        <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
            <span class="font-mono font-semibold text-ink-900 dark:text-ivory {{ $big ? 'text-xl' : 'text-base' }}" dir="ltr">{{ $card->number }}</span>
            @if ($card->part)<span class="text-xs text-ink-500 dark:text-ink-400 {{ $big ? 'text-sm' : '' }}">{{ $card->part }}</span>@endif
        </div>
        @if ($card->age && $card->age->kind === 'out')
            {{-- On the road there is no limit to be late against: a plain time, in a neutral chip. --}}
            <span class="badge-neutral shrink-0 tabular-nums {{ $big ? 'text-sm' : '' }}" title="{{ $card->age->label }}"><x-icon name="truck" class="size-3.5" />{{ $card->age->label }}</span>
        @elseif ($card->age)
            <x-admin.ops.age-chip :state="$card->age->state" :label="$card->age->label" :title="$chipTitle" class="{{ $big ? 'text-sm' : '' }}" />
        @endif
    </div>
    <p class="mt-1 font-medium text-ink-700 dark:text-sand-200 {{ $big ? 'text-base' : 'text-sm' }}">{{ __('ops_board.stage.'.$card->stage) }}</p>

    @if ($card->lane === 'attention')
        <div class="mt-2 flex flex-wrap items-center gap-1.5">
            @if ($card->reason)<span class="{{ in_array($card->reason, ['late', 'failed'], true) ? 'badge-danger' : 'badge-warning' }} {{ $big ? 'text-sm' : '' }}"><x-icon name="alert" class="size-3.5" />{{ __('ops_board.reason.'.$card->reason) }}</span>@endif
            <span class="badge-neutral {{ $big ? 'text-sm' : '' }}">{{ __('ops_board.was', ['lane' => $lane($card->from)]) }}</span>
        </div>
    @endif
    @if ($card->note)<p class="mt-1.5 text-xs text-amber-soft dark:text-amber-300 {{ $big ? 'text-sm' : '' }}">{{ $card->note }}</p>@endif

    <div class="mt-2 space-y-1 text-ink-600 dark:text-ink-300 {{ $big ? 'text-sm' : 'text-xs' }}">
        <p class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <span class="inline-flex items-center gap-1.5"><x-icon name="package" class="size-3.5 shrink-0" />{{ trans_choice('ops_board.items', $card->units, ['count' => $card->units]) }}</span>
            @if ($card->when)<span class="inline-flex items-center gap-1.5 tabular-nums"><x-icon name="calendar" class="size-3.5 shrink-0" /><span dir="auto">{{ $card->when }}</span></span>@endif
            @if ($card->express)<span class="badge-brass">{{ __('ops_board.express') }}</span>@endif
        </p>
        <p class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <span class="inline-flex items-center gap-1.5 {{ $cash ? 'font-semibold text-ink-900 dark:text-ivory' : '' }}"><x-icon :name="$cash ? 'cash' : 'credit-card'" class="size-3.5 shrink-0" /><span dir="auto">{{ $pay }}</span></span>
            @if ($card->driver)<span class="inline-flex items-center gap-1.5"><x-icon name="truck" class="size-3.5 shrink-0" /><span dir="auto">{{ __('ops_board.driver', ['name' => $card->driver]) }}</span></span>@endif
        </p>
    </div>

    @if ($card->waitingCustomer)
        <p class="mt-2"><span class="badge-info {{ $big ? 'text-sm' : '' }}"><x-icon name="message" class="size-3.5" />{{ __('ops_board.waiting_customer') }}</span></p>
    @endif

    @unless ($wall)
        <div class="mt-3 flex flex-wrap items-center gap-2">
            <button type="button" wire:click="peekOrder('{{ $card->orderId }}')" class="btn-secondary btn-sm min-h-11" aria-label="{{ __('ops_board.open_order', ['number' => $card->number]) }}">{{ __('ops_board.open') }}</button>
            @if ($card->canAccept)
                <button type="button" wire:click="acceptPart('{{ $card->id }}')" wire:loading.attr="disabled" wire:target="acceptPart('{{ $card->id }}')" class="btn-primary btn-sm min-h-11" aria-label="{{ __('ops_board.accept_label', ['number' => $card->number]) }}"><x-icon name="check" class="size-4" />{{ __('ops_board.accept') }}</button>
            @elseif ($card->assign)
                <a href="{{ $card->assign }}" wire:navigate class="btn-primary btn-sm min-h-11">{{ __('ops_board.assign.'.$card->stage) }}<x-icon name="arrow-right" class="size-4 rtl-flip" /></a>
            @endif
        </div>
    @endunless
</article>
