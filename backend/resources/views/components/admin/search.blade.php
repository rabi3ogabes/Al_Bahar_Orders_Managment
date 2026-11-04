@props(['placeholder' => null, 'model' => 'search'])
<label class="relative block w-full sm:max-w-xs">
    <span class="sr-only">{{ $placeholder ?? __('admin.search') }}</span>
    <x-icon name="search" class="pointer-events-none absolute top-1/2 start-3.5 size-4 -translate-y-1/2 text-ink-400" />
    <input type="search" wire:model.live.debounce.350ms="{{ $model }}" placeholder="{{ $placeholder ?? __('admin.search') }}" class="input ps-10" {{ $attributes }}>
</label>
