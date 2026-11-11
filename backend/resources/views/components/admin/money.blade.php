@props(['value', 'currency' => 'QAR'])
{{-- Locale-aware QAR amount (always LTR digits, tabular). --}}
<span dir="ltr" {{ $attributes->merge(['class' => 'tabular-nums whitespace-nowrap']) }}>{{ \App\Support\Money::of($value ?? '0', $currency ?: 'QAR')->format() }}</span>
