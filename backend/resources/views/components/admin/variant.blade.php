@props(['variant', 'sku' => true])
{{-- Product + variant label. Expects variant.product.translations (and attributeValues for unnamed variants) eager-loaded. --}}
@php
    $product = $variant->product;
    $vName = $product?->has_variants ? $variant->displayName() : null;
@endphp
<span {{ $attributes->merge(['class' => 'block min-w-0']) }}>
    <span class="block truncate font-medium text-ink-900 dark:text-ivory">{{ $product?->trans('name') ?? '—' }}</span>
    <span class="flex items-center gap-2 truncate text-xs text-ink-500">
        @if ($sku)<span class="font-mono" dir="ltr">{{ $variant->sku }}</span>@endif
        @if ($vName)<span class="truncate">· {{ $vName }}</span>@endif
    </span>
</span>
