@props(['note'])
{{-- What a sent notification did on the phones (from DevicePushReach::deliveryNote): reached N phones with notifications off on M, or no phone at all. Nothing in the normal case. --}}
@if ($note)
    @php
        $silent = $note['kind'] === 'silent';
        $text = $silent
            ? trans_choice('messaging_phones.note.silent', max(1, $note['reached'], $note['silent']), ['count' => number_format(max(1, $note['reached'], $note['silent'])), 'silent' => number_format($note['silent'])])
            : __('messaging_phones.note.no_phone');
        $hint = $silent ? __('messaging_phones.note.hint') : __('messaging_phones.note.no_phone_hint');
    @endphp
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 font-medium text-amber-soft']) }} title="{{ $hint }}" data-testid="phones-note" data-kind="{{ $note['kind'] }}"><x-icon name="alert" class="size-3 shrink-0" />{{ $text }}<span class="sr-only">. {{ $hint }}</span></span>
@endif
