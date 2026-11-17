@props(['on' => false, 'label', 'disabled' => false])
{{-- Instant on/off switch (role="switch"): the change acts at once, there is no Save button next to it. 44px touch target, follows the reading direction.
     Pass wire:click, wire:loading.attr, wire:target as attributes; `label` is the accessible name (the visible text sits next to it). --}}
<button type="button" role="switch" aria-checked="{{ $on ? 'true' : 'false' }}" aria-label="{{ $label }}" @disabled($disabled)
        {{ $attributes->merge(['class' => 'inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-full focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-40']) }}>
    <span class="relative inline-flex h-7 w-12 items-center rounded-full transition-colors duration-200 {{ $on ? 'bg-emerald-soft' : 'bg-sand-300 dark:bg-ink-600' }}">
        <span class="inline-block size-5 rounded-full bg-white shadow transition-transform duration-200 {{ $on ? 'translate-x-6 rtl:-translate-x-6' : 'translate-x-1 rtl:-translate-x-1' }}"></span>
    </span>
</button>
