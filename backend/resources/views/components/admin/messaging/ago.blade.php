@props(['at'])
{{-- "3 days ago" with the exact business-time date in the tooltip (and in the machine-readable datetime). --}}
@php $t = \App\Support\BusinessTime::local($at); @endphp
@if ($t)<time datetime="{{ $t->toIso8601String() }}" title="{{ \App\Support\BusinessTime::display($t, 'D d M Y, H:i') }}" {{ $attributes->merge(['class' => 'tabular-nums']) }}>{{ $t->locale(app()->getLocale())->diffForHumans() }}</time>@endif
