@props(['state' => 'ok', 'label', 'title' => null])
{{--
    How long something has waited, and how worried to be. Never colour alone: every state has its own shape (dot, clock, warning sign), the
    age as text, and a word for screen readers. Used by the orders list, the "Needs attention" inbox and the dashboard card.
    <x-admin.ops.age-chip :state="$age->state" :label="$age->label" :title="..." />
--}}
@php
    $state = in_array($state, ['ok', 'amber', 'red'], true) ? $state : 'ok';
    $tone = match ($state) {
        'red' => 'bg-ruby-soft/10 text-ruby-soft ring-ruby-soft/30 dark:text-red-300',
        'amber' => 'bg-amber-soft/10 text-amber-soft ring-amber-soft/30 dark:text-amber-300',
        default => 'bg-emerald-soft/10 text-emerald-soft ring-emerald-soft/25 dark:text-emerald-300',
    };
    $word = __('ops_live.state.'.$state);
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium tabular-nums ring-1 ring-inset '.$tone, 'title' => $title ?: $word.' · '.$label, 'data-age-state' => $state]) }}>
    @if ($state === 'ok')
        <span class="size-2 rounded-full bg-current" aria-hidden="true"></span>
    @else
        <x-icon :name="$state === 'red' ? 'alert' : 'clock'" class="size-3.5" />
    @endif
    <span>{{ $label }}</span>
    <span class="sr-only">{{ $word }}</span>
</span>
