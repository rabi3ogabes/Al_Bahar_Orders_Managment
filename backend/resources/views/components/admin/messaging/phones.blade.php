@props(['reach'])
{{-- The "Phones" line of one customer: how many phones are registered and whether a push would show on them (see DevicePushReach). --}}
@php
    use App\Domain\Notifications\Services\DevicePushReach;

    $state = DevicePushReach::state($reach);
    $devices = (int) $reach['devices'];
    $text = match (true) {
        $state === 'none' => __('messaging_phones.line.none'),
        $state === 'on' && $reach['can_show'] < $devices => trans_choice('messaging_phones.line.on_some', $devices, ['count' => number_format($devices), 'on' => number_format($reach['can_show'])]),
        default => trans_choice('messaging_phones.line.'.$state, $devices, ['count' => number_format($devices)]),
    };
    // Green: a push shows. Grey: not known (an older app). Amber: the person has not allowed notifications, or there is no phone.
    $warn = in_array($state, ['none', 'off', 'not_asked'], true) || ($state === 'on' && $reach['can_show'] < $devices);
    $tone = $state === 'unknown' ? 'text-ink-500 dark:text-ink-400' : ($warn ? 'text-amber-soft' : 'text-emerald-soft dark:text-emerald-300');
@endphp
<p {{ $attributes->merge(['class' => 'flex items-start gap-1.5 text-xs']) }} data-testid="phones-line" data-state="{{ $state }}">
    <x-icon :name="$warn ? 'alert' : ($state === 'unknown' ? 'info' : 'check')" class="mt-px size-3.5 shrink-0 {{ $tone }}" />
    <span class="min-w-0 leading-relaxed text-ink-600 dark:text-sand-200"><span class="font-semibold text-ink-900 dark:text-ivory">{{ __('messaging_phones.label') }}</span> · {{ $text }}</span>
</p>
