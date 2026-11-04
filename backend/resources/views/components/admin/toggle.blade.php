@props(['label', 'hint' => null])
{{-- Labelled checkbox row. Pass wire:model etc. as attributes. --}}
<label class="flex cursor-pointer items-start justify-between gap-4 rounded-xl border border-sand-100 px-4 py-3 transition hover:border-sand-200 dark:border-ink-800 dark:hover:border-ink-700">
    <span class="min-w-0"><span class="block text-sm text-ink-800 dark:text-sand-100">{{ $label }}</span>@if ($hint)<span class="mt-0.5 block text-xs text-ink-500">{{ $hint }}</span>@endif</span>
    <input type="checkbox" {{ $attributes->merge(['class' => 'checkbox mt-0.5 shrink-0']) }}>
</label>
