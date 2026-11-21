{{-- Whether the board is live (the socket is connected), reconnecting, or checking on a timer; and when it stopped updating at all (red). Alpine in resources/js/ops-board.js. --}}
<span class="inline-flex min-h-9 items-center gap-2 rounded-full px-3 font-medium ring-1 ring-inset {{ $big ? 'min-h-11 px-4 text-base' : 'text-xs' }}" role="status" data-link
      x-bind:class="stale ? 'bg-ruby-soft/10 text-ruby-soft ring-ruby-soft/30 dark:text-red-300' : (link === 'live' ? 'bg-emerald-soft/10 text-emerald-soft ring-emerald-soft/25 dark:text-emerald-300' : (link === 'reconnecting' ? 'bg-amber-soft/10 text-amber-soft ring-amber-soft/30 dark:text-amber-300' : 'bg-sand-100 text-ink-600 ring-sand-300 dark:bg-ink-800 dark:text-sand-200 dark:ring-ink-600'))">
    <span class="size-2 rounded-full bg-current" x-bind:class="link === 'live' && ! stale ? 'animate-pulse motion-reduce:animate-none' : ''" aria-hidden="true"></span>
    <span x-text="linkText">{{ __('ops_board.link.polling', ['s' => 10]) }}</span>
    <span class="hidden tabular-nums opacity-75 sm:inline" dir="ltr">{{ $renderedAt->format('H:i:s') }}</span>
</span>
