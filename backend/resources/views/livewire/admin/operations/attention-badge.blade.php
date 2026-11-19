<span class="ms-auto" wire:poll.30s.visible x-data x-on:ops-order-live.window.debounce.1500ms="$wire.$refresh()" data-attention-badge="{{ $summary['total'] }}">
    @if ($summary['total'] > 0)
        <span class="grid min-w-5 place-items-center rounded-full px-1.5 text-[0.7rem] font-semibold leading-5 tabular-nums {{ $summary['red'] > 0 ? 'bg-ruby-soft text-white' : 'bg-brass-500 text-ink-950' }}" title="{{ __('ops_live.badge', ['count' => $summary['total']]) }}">
            <span aria-hidden="true">{{ $summary['total'] > 99 ? '99+' : $summary['total'] }}</span><span class="sr-only">{{ __('ops_live.badge', ['count' => $summary['total']]) }}</span>
        </span>
    @endif
</span>
