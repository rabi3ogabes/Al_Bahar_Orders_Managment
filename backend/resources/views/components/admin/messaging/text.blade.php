@props(['value'])
{{-- A message text as the team wrote it: line breaks kept, and each {{placeholder}} shown as a small chip with its plain name (First name, Coupon code …). --}}
@php
    $parts = preg_split('/(\{\{\s*\w+\s*\}\})/u', (string) $value, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
@endphp
<span {{ $attributes->merge(['class' => 'whitespace-pre-line break-words']) }}>@foreach ($parts as $part)@if (preg_match('/^\{\{\s*(\w+)\s*\}\}$/u', $part, $m))<span class="mx-px inline-block rounded-md bg-brass-50 px-1.5 py-0.5 text-[0.8em] font-medium leading-tight text-brass-700 dark:bg-brass-500/15 dark:text-brass-300" dir="auto">{{ \Illuminate\Support\Facades\Lang::has('messaging_admin.placeholder.'.$m[1]) ? __('messaging_admin.placeholder.'.$m[1]) : $part }}</span>@else{{ $part }}@endif@endforeach</span>
