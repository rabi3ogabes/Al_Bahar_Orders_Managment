@props(['label', 'value', 'icon' => null, 'trend' => null, 'hint' => null, 'tone' => 'neutral'])
<div {{ $attributes->merge(['class' => 'card card-pad relative overflow-hidden']) }}>
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="text-sm text-ink-500 dark:text-ink-400">{{ $label }}</p>
            <p class="mt-2 font-display text-3xl font-semibold tracking-tight text-ink-900 tabular-nums dark:text-ivory">{{ $value }}</p>
        </div>
        @if ($icon)
            <span class="grid size-11 shrink-0 place-items-center rounded-xl {{ $tone === 'brass' ? 'bg-brass-50 text-brass-600 dark:bg-brass-500/15 dark:text-brass-300' : 'bg-sand-100 text-ink-600 dark:bg-ink-800 dark:text-sand-200' }}">
                <x-icon :name="$icon" />
            </span>
        @endif
    </div>
    @if ($trend !== null || $hint)
        <div class="mt-4 flex items-center gap-2 text-xs">
            @if ($trend !== null)
                <span class="{{ $trend >= 0 ? 'badge-success' : 'badge-danger' }}">{{ $trend >= 0 ? '▲' : '▼' }} {{ abs($trend) }}%</span>
            @endif
            @if ($hint)<span class="text-ink-500 dark:text-ink-400">{{ $hint }}</span>@endif
        </div>
    @endif
</div>
