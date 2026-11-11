{{-- Filter bar at the top of a list card. --}}
<div {{ $attributes->merge(['class' => 'flex flex-col gap-3 border-b border-sand-100 p-4 sm:flex-row sm:flex-wrap sm:items-center dark:border-ink-800']) }}>
    {{ $slot }}
    <div class="sm:ms-auto" wire:loading.delay><x-icon name="refresh" class="size-4 animate-spin text-brass-500" /></div>
    @isset($meta)<div class="text-sm text-ink-500 sm:ms-auto" wire:loading.remove.delay>{{ $meta }}</div>@endisset
</div>
