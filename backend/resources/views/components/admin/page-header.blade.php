@props(['title', 'eyebrow' => null, 'description' => null])
<div {{ $attributes->merge(['class' => 'mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between']) }}>
    <div class="min-w-0">
        @if ($eyebrow)<p class="eyebrow mb-2">{{ $eyebrow }}</p>@endif
        <h1 class="font-display text-3xl font-semibold tracking-tight text-ink-900 sm:text-4xl dark:text-ivory">{{ $title }}</h1>
        @if ($description)<p class="mt-2 max-w-2xl text-sm leading-relaxed text-ink-500 dark:text-ink-400">{{ $description }}</p>@endif
    </div>
    @isset($actions)<div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>@endisset
</div>
