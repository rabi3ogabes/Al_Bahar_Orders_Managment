@props(['steps'])
{{-- $steps: list of ['label', 'at' => ?string, 'by' => ?string, 'state' => done|current|todo|failed] --}}
<ol {{ $attributes->merge(['class' => 'relative space-y-5']) }}>
    @foreach ($steps as $i => $s)
        @php
            $dot = match ($s['state']) {
                'done' => 'bg-emerald-soft text-white', 'current' => 'bg-brass-500 text-ink-950 ring-4 ring-brass-500/20',
                'failed' => 'bg-ruby-soft text-white', default => 'bg-sand-100 text-ink-400 dark:bg-ink-800',
            };
        @endphp
        <li class="relative flex gap-4">
            @if (! $loop->last)<span class="absolute top-7 bottom-[-1.25rem] start-3 w-px {{ $s['state'] === 'done' ? 'bg-emerald-soft/40' : 'bg-sand-200 dark:bg-ink-700' }}" aria-hidden="true"></span>@endif
            <span class="relative z-10 grid size-6 shrink-0 place-items-center rounded-full {{ $dot }}">
                @if ($s['state'] === 'done')<x-icon name="check" class="size-3.5" />@elseif ($s['state'] === 'failed')<x-icon name="x" class="size-3.5" />@else<span class="size-1.5 rounded-full bg-current"></span>@endif
            </span>
            <div class="min-w-0 pb-0.5">
                <p class="text-sm font-medium {{ $s['state'] === 'todo' ? 'text-ink-400' : 'text-ink-900 dark:text-ivory' }}">{{ $s['label'] }}</p>
                @if (! empty($s['at']) || ! empty($s['by']))
                    <p class="mt-0.5 text-xs text-ink-500 tabular-nums">{{ $s['at'] ?? '' }}@if (! empty($s['by'])) · {{ $s['by'] }}@endif</p>
                @endif
            </div>
        </li>
    @endforeach
</ol>
