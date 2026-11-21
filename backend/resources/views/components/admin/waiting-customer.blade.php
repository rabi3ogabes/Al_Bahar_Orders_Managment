@props(['deadline'])
{{-- "Waiting for the customer" with a live clock to the moment their time to answer runs out (the deadline is stored: opening hours were counted when it was set). --}}
<span {{ $attributes->merge(['class' => 'badge-warning inline-flex items-center gap-1.5']) }} role="status"
      x-data="{ at: {{ \Carbon\CarbonImmutable::parse($deadline)->getTimestamp() * 1000 }}, left: '', t: null,
                init() { this.tick(); this.t = setInterval(() => this.tick(), 1000); },
                destroy() { clearInterval(this.t); },
                tick() { const s = Math.max(0, Math.round((this.at - Date.now()) / 1000));
                         this.left = s <= 0 ? @js(__('substitutions.time_up')) : @js(__('substitutions.time_left', ['time' => '#'])).replace('#', Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0')); } }">
    <x-icon name="clock" class="size-3.5" />{{ __('substitutions.waiting') }} · <span x-text="left" class="tabular-nums"></span>
</span>
