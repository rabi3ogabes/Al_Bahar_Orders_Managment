@props(['channel', 'label' => true, 'size' => 'size-3.5'])
{{-- A delivery channel as icon + name. Manual sends use notification / whatsapp; automatic journeys can also use sms or email. --}}
@php
    use Illuminate\Support\Facades\Lang;
    $name = in_array($channel, ['notification', 'whatsapp'], true) ? __('messaging_admin.channel.'.$channel)
        : (Lang::has('content_admin.ch_'.$channel) ? __('content_admin.ch_'.$channel) : \Illuminate\Support\Str::headline($channel));
    $icon = match ($channel) { 'whatsapp' => 'whatsapp', 'sms' => 'phone', 'email' => 'mail', 'notification', 'push', 'in_app' => 'bell', default => 'megaphone' };
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 text-xs font-medium text-ink-700 dark:text-sand-200']) }} @unless ($label) title="{{ $name }}" @endunless>
    <x-icon :name="$icon" :class="\Illuminate\Support\Arr::toCssClasses([$size.' shrink-0', 'text-emerald-soft' => $channel === 'whatsapp', 'text-brass-600 dark:text-brass-300' => $channel !== 'whatsapp'])" />@if ($label){{ $name }}@else<span class="sr-only">{{ $name }}</span>@endif
</span>
