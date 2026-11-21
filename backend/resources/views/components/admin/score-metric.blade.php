@props(['label', 'metric', 'kind' => 'percent', 'min' => 10, 'note' => null, 'of' => null, 'big' => false, 'tip' => null])
{{--
    One scorecard number with the words around it. $metric is what ScorecardStats::metric() returns:
      ok    the number, and how it moved against the period before (an arrow with words, never a colour that says good or bad);
      few   "Not enough data yet" and how many cases there are so far;
      none  "Nothing to measure in this period".
    $note is the one-line "how this is measured", shown under the number; $tip is the same thing, shorter, for a small card where a line under every number would
    crowd it: it is the tooltip of the label. Put the widget inside a <dl>.
--}}
@php
    use App\Domain\Reports\Services\ScorecardFormat;
    use App\Domain\Reports\Services\ScorecardStats;

    $state = $metric['state'];
    $change = $state === ScorecardStats::OK ? ScorecardFormat::change($kind, $metric['change']) : null;
@endphp
<div {{ $attributes->merge(['class' => 'min-w-0']) }} data-metric-state="{{ $state }}">
    <dt class="text-sm text-ink-600 dark:text-ink-300" @if ($tip) title="{{ $tip }}" @endif>{{ $label }}</dt>
    <dd class="mt-0.5">
        @if ($state === ScorecardStats::OK)
            <span class="font-display {{ $big ? 'text-3xl sm:text-4xl' : 'text-2xl' }} font-semibold tabular-nums text-ink-900 dark:text-ivory" dir="ltr" data-testid="value">{{ ScorecardFormat::value($kind, $metric['value']) }}</span>
            @if ($change)
                <span class="ms-2 inline-flex items-center gap-1 whitespace-nowrap text-xs text-ink-500 dark:text-ink-400" data-change="{{ $change['direction'] }}"><span aria-hidden="true">{{ $change['arrow'] }}</span>@if ($change['text'] !== '')<span dir="ltr">{{ $change['text'] }}</span>@endif<span class="sr-only">{{ $change['words'] }}</span></span>
            @elseif ($metric['previous_state'] !== ScorecardStats::OK)
                @php $why = __('scorecard.no_compare_'.$metric['previous_state']); @endphp
                <span class="ms-2 text-xs text-ink-400" title="{{ $why }}" data-testid="no-compare">{{ __('scorecard.no_compare') }}<span class="sr-only">. {{ $why }}</span></span>
            @endif
            @if ($of)<span class="block text-xs text-ink-500 dark:text-ink-400">{{ $of }}</span>@endif
        @elseif ($state === ScorecardStats::FEW)
            <span class="text-sm font-semibold text-ink-700 dark:text-sand-200" data-testid="not-enough">{{ __('scorecard.not_enough') }}</span>
            <span class="block text-xs text-ink-500 dark:text-ink-400">{{ __('scorecard.needs', ['n' => $metric['n'], 'min' => $min]) }}</span>
        @else
            <span class="text-2xl font-semibold text-ink-300" aria-hidden="true">—</span>
            <span class="block text-xs text-ink-500 dark:text-ink-400">{{ __('scorecard.nothing') }}</span>
        @endif
    </dd>
    @if ($note)<p class="mt-1 text-xs leading-snug text-ink-500 dark:text-ink-400">{{ $note }}</p>@endif
</div>
