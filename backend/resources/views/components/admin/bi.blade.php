@props(['en', 'ar', 'inline' => false])
{{-- Both languages on the same screen, whatever the dashboard language is: English, then Arabic (right-to-left, Arabic font). --}}
@if ($inline)
    <span {{ $attributes }}>{{ $en }} <span class="opacity-60">·</span> <span lang="ar" dir="rtl" class="font-arabic">{{ $ar }}</span></span>
@else
    <span {{ $attributes->merge(['class' => 'inline-flex flex-col leading-tight']) }}><span lang="en" dir="ltr">{{ $en }}</span><span lang="ar" dir="rtl" class="font-arabic text-[0.92em] opacity-75">{{ $ar }}</span></span>
@endif
