@props(['name', 'title' => null, 'maxWidth' => 'max-w-lg'])
{{-- Open with: $dispatch('open-modal', '{{ $name }}') · Close: $dispatch('close-modal', '{{ $name }}') --}}
<div x-data="{ open: false }"
     x-on:open-modal.window="if ($event.detail === '{{ $name }}') open = true"
     x-on:close-modal.window="if ($event.detail === '{{ $name }}') open = false"
     x-on:keydown.escape.window="open = false"
     x-cloak x-show="open" class="fixed inset-0 z-[70] flex items-end justify-center p-4 sm:items-center" role="dialog" aria-modal="true">
    <div x-show="open" x-transition.opacity class="absolute inset-0 bg-ink-950/60 backdrop-blur-sm" @click="open = false"></div>
    <div x-show="open" x-transition class="card relative w-full {{ $maxWidth }} p-6 shadow-[var(--shadow-lift)]" x-trap.noscroll="open">
        @if ($title)
            <div class="mb-5 flex items-start justify-between gap-4">
                <h2 class="font-display text-2xl font-semibold text-ink-900 dark:text-ivory">{{ $title }}</h2>
                <button type="button" class="btn-ghost -me-2 -mt-1 p-2" @click="open = false" aria-label="{{ __('admin.close') }}"><x-icon name="x" class="size-4" /></button>
            </div>
        @endif
        {{ $slot }}
    </div>
</div>
