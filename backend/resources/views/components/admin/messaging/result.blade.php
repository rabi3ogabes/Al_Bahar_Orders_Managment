@props(['sent' => 0, 'delivered' => 0, 'failed' => 0, 'skipped' => 0, 'queued' => 0, 'legend' => true])
{{-- What happened to the messages of one send: a stacked bar (delivered, sent, failed, not sent, waiting) and a legend with the plain numbers.
     "sent" counts everything handed to the channel, so it already includes the delivered ones. --}}
@php
    $sentOnly = max(0, $sent - $delivered);
    $total = $delivered + $sentOnly + $failed + $skipped + $queued;
    $segments = [
        ['delivered', $delivered, 'bg-emerald-soft'],
        ['sent', $sentOnly, 'bg-emerald-soft/50'],
        ['failed', $failed, 'bg-ruby-soft'],
        ['skipped', $skipped, 'bg-ink-300 dark:bg-ink-600'],
        ['queued', $queued, 'bg-amber-soft/70'],
    ];
    $summary = collect([
        'sent' => $sent, 'delivered' => $delivered, 'failed' => $failed, 'skipped' => $skipped, 'queued' => $queued,
    ])->filter()->map(fn ($n, $k) => number_format($n).' '.__('messaging_history.legend.'.$k))->implode(', ');
@endphp
<div {{ $attributes->merge(['class' => 'min-w-0']) }}>
    <div class="flex h-2 overflow-hidden rounded-full bg-sand-100 dark:bg-ink-800" role="img" aria-label="{{ $total ? $summary : __('messaging_history.nothing_yet') }}">
        @if ($total)
            @foreach ($segments as [$key, $n, $color])
                @if ($n > 0)<span class="{{ $color }} {{ $key === 'queued' ? 'animate-pulse motion-reduce:animate-none' : '' }}" style="width: {{ number_format($n / $total * 100, 2, '.', '') }}%"></span>@endif
            @endforeach
        @endif
    </div>
    @if ($legend)
        <p class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-600 dark:text-ink-300">
            @if ($total === 0)
                <span class="text-ink-500">{{ __('messaging_history.nothing_yet') }}</span>
            @else
                @foreach ([['sent', $sent, 'bg-emerald-soft/50'], ['failed', $failed, 'bg-ruby-soft'], ['skipped', $skipped, 'bg-ink-300 dark:bg-ink-600'], ['queued', $queued, 'bg-amber-soft/70']] as [$key, $n, $dot])
                    @if ($n > 0 || $key === 'sent')
                        <span class="inline-flex items-center gap-1.5 tabular-nums"><span class="size-2 rounded-full {{ $dot }}" aria-hidden="true"></span><span class="font-medium text-ink-900 dark:text-ivory">{{ number_format($n) }}</span> {{ __('messaging_history.legend.'.$key) }}@if ($key === 'sent' && $delivered > 0)<span class="text-ink-500"> · {{ number_format($delivered) }} {{ __('messaging_history.legend.delivered') }}</span>@endif</span>
                    @endif
                @endforeach
            @endif
        </p>
    @endif
</div>
