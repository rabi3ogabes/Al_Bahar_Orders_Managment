@props(['title', 'description' => null, 'icon' => 'sparkles'])
<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center px-6 py-16 text-center']) }}>
    <div class="mb-5 grid size-14 place-items-center rounded-2xl bg-sand-100 text-brass-600 dark:bg-ink-800 dark:text-brass-300">
        <x-icon :name="$icon" class="size-6" />
    </div>
    <h3 class="font-display text-xl font-semibold text-ink-900 dark:text-ivory">{{ $title }}</h3>
    @if ($description)<p class="mt-2 max-w-sm text-sm text-ink-500 dark:text-ink-400">{{ $description }}</p>@endif
    @if (! $slot->isEmpty())<div class="mt-6">{{ $slot }}</div>@endif
</div>
