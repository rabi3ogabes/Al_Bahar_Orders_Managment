@props(['value', 'scope' => 'message'])
{{-- Status chip of one message (scope=message) or of a whole send (scope=batch), worded by messaging_admin. --}}
@php
    use Illuminate\Support\Facades\Lang;
    $tone = match ($scope.'.'.$value) {
        'message.delivered', 'batch.done' => 'badge-success',
        'message.sent', 'batch.scheduled' => 'badge-info',
        'message.failed' => 'badge-danger',
        'message.queued', 'batch.sending' => 'badge-warning',
        default => 'badge-neutral',
    };
    $icon = match ($scope.'.'.$value) {
        'message.delivered', 'message.sent', 'batch.done' => 'check', 'message.failed' => 'alert', 'message.queued', 'batch.scheduled' => 'clock',
        'batch.sending' => 'refresh', 'message.cancelled', 'batch.cancelled' => 'ban', default => 'minus',
    };
    $key = $scope === 'batch' ? 'messaging_admin.batch_status.' : 'messaging_admin.status.';
    $label = Lang::has($key.$value) ? __($key.$value) : \Illuminate\Support\Str::headline((string) $value);
@endphp
<span {{ $attributes->merge(['class' => $tone]) }}><x-icon :name="$icon" :class="\Illuminate\Support\Arr::toCssClasses(['size-3', 'animate-spin motion-reduce:animate-none' => $scope.'.'.$value === 'batch.sending'])" />{{ $label }}</span>
