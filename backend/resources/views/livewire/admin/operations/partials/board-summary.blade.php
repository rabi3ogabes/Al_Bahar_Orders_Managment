{{-- How many open parts are late, getting late, and open in all: the same three states and shapes as the order clock. --}}
@php
    $total = $counts['red'] + $counts['amber'] + $counts['ok'];
    $chips = [
        ['red', 'alert', 'bg-ruby-soft/10 text-ruby-soft dark:text-red-300', trans_choice('ops_board.summary.late', $counts['red'], ['count' => $counts['red']])],
        ['amber', 'clock', 'bg-amber-soft/10 text-amber-soft dark:text-amber-300', trans_choice('ops_board.summary.amber', $counts['amber'], ['count' => $counts['amber']])],
        ['open', 'package', 'bg-sand-100 text-ink-700 dark:bg-ink-800 dark:text-sand-200', trans_choice('ops_board.summary.open', $total, ['count' => $total])],
    ];
@endphp
@foreach ($chips as [$key, $icon, $tone, $text])
    <span class="inline-flex min-h-9 items-center gap-2 rounded-full px-3.5 font-medium tabular-nums {{ $tone }} {{ $big ? 'min-h-11 px-4 text-lg' : 'text-sm' }}" data-summary="{{ $key }}"><x-icon :name="$icon" class="{{ $big ? 'size-5' : 'size-4' }}" />{{ $text }}</span>
@endforeach
