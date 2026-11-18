@props(['note'])
{{-- What a sent notification did on the phones (from DevicePushReach::deliveryNote): reached N phones with notifications off on M, no phone at all, Google refused the push, or no push was sent because the customer switched it off / push is not set up. Nothing in the normal case. --}}
@if ($note)
    @php
        $kind = $note['kind'];
        $text = match ($kind) {
            'silent' => trans_choice('messaging_phones.note.silent', max(1, $note['reached'], $note['silent']), ['count' => number_format(max(1, $note['reached'], $note['silent'])), 'silent' => number_format($note['silent'])]),
            'no_phone' => __('messaging_phones.note.no_phone'),
            default => __('messaging_phones.note.'.$kind),
        };
        $hint = match ($kind) {
            'silent' => __('messaging_phones.note.hint'),
            'no_phone' => __('messaging_phones.note.no_phone_hint'),
            'rejected' => __('messaging_phones.note.rejected_hint', ['error' => $note['error'] ?? __('messaging_composer.res_one.unknown_error')]),
            default => __('messaging_phones.note.'.$kind.'_hint'),
        };
    @endphp
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 font-medium text-amber-soft']) }} title="{{ $hint }}" data-testid="phones-note" data-kind="{{ $kind }}"><x-icon name="alert" class="size-3 shrink-0" />{{ $text }}<span class="sr-only">. {{ $hint }}</span></span>
@endif
