@props(['title' => null, 'description' => null, 'maxWidth' => 'max-w-lg'])
{{-- Server-driven dialog: <x-admin.dialog wire:model="showEditor" ...>. Closing (Esc, backdrop, ×) syncs back to Livewire. --}}
@php $model = $attributes->wire('model')->value(); @endphp
<div x-data="{ open: $wire.entangle('{{ $model }}') }"
     x-on:keydown.escape.window="open = false"
     x-cloak x-show="open" class="fixed inset-0 z-[70] flex items-end justify-center overflow-y-auto p-3 sm:items-center sm:p-6" role="dialog" aria-modal="true" @if($title) aria-label="{{ $title }}" @endif>
    <div x-show="open" x-transition.opacity class="fixed inset-0 bg-ink-950/60 backdrop-blur-sm" @click="open = false"></div>
    <div x-show="open" x-transition class="card relative my-auto w-full {{ $maxWidth }} p-5 shadow-[var(--shadow-lift)] sm:p-7" x-trap.noscroll="open">
        @if ($title)
            <div class="mb-6 flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <h2 class="font-display text-2xl font-semibold text-ink-900 dark:text-ivory">{{ $title }}</h2>
                    @if ($description)<p class="mt-1 text-sm text-ink-500 dark:text-ink-400">{{ $description }}</p>@endif
                </div>
                <button type="button" class="btn-ghost -me-2 -mt-1 p-2" @click="open = false" aria-label="{{ __('admin.close') }}"><x-icon name="x" class="size-4" /></button>
            </div>
        @endif
        {{ $slot }}
    </div>
</div>
