@props(['label', 'note' => null, 'value', 'icon' => null, 'tone' => 'neutral', 'hint' => null])
{{-- A headline number with a one-line explanation of what it counts, right under its label (the owner decides from these). --}}
<div {{ $attributes->merge(['class' => 'card card-pad relative overflow-hidden']) }}>
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="text-sm font-medium text-ink-700 dark:text-sand-200">{{ $label }}</p>
            @if ($note)<p class="mt-0.5 text-xs leading-snug text-ink-500 dark:text-ink-400">{{ $note }}</p>@endif
            <p class="mt-3 font-display text-3xl font-semibold tracking-tight text-ink-900 tabular-nums dark:text-ivory">{{ $value }}</p>
        </div>
        @if ($icon)
            <span class="grid size-11 shrink-0 place-items-center rounded-xl {{ $tone === 'brass' ? 'bg-brass-50 text-brass-600 dark:bg-brass-500/15 dark:text-brass-300' : 'bg-sand-100 text-ink-600 dark:bg-ink-800 dark:text-sand-200' }}">
                <x-icon :name="$icon" />
            </span>
        @endif
    </div>
    @if ($hint)<p class="mt-4 text-xs text-ink-500 dark:text-ink-400">{{ $hint }}</p>@endif
</div>
