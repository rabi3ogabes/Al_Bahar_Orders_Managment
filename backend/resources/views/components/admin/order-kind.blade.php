@props(['method' => null, 'type' => null])
@php
    // COD / SADAD / pay-at-pickup, and delivery vs pickup — each a chip with its own icon, both languages.
    $pay = [
        'cod' => ['cash', 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/30'],
        'sadad' => ['credit-card', 'bg-sky-50 text-sky-800 ring-sky-200 dark:bg-sky-500/10 dark:text-sky-200 dark:ring-sky-500/30'],
        'pay_at_pickup' => ['store', 'bg-violet-50 text-violet-800 ring-violet-200 dark:bg-violet-500/10 dark:text-violet-200 dark:ring-violet-500/30'],
    ][$method] ?? ['credit-card', 'bg-sand-100 text-ink-700 ring-sand-200 dark:bg-ink-800 dark:text-sand-100 dark:ring-ink-700'];
    $isPickup = $type === 'pickup';
@endphp
<div {{ $attributes->merge(['class' => 'flex flex-col items-start gap-1.5']) }}>
    @if ($method)
    <span class="inline-flex items-center gap-2 rounded-xl px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $pay[1] }}">
        <x-icon :name="$pay[0]" class="size-3.5 shrink-0" />
        <x-admin.bi :en="__('status.'.$method, [], 'en')" :ar="__('status.'.$method, [], 'ar')" />
    </span>
    @endif
    @if ($type)
        <span class="inline-flex items-center gap-2 rounded-xl px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $isPickup ? 'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-200 dark:ring-emerald-500/30' : 'bg-sand-100 text-ink-700 ring-sand-200 dark:bg-ink-800 dark:text-sand-100 dark:ring-ink-700' }}">
            <x-icon :name="$isPickup ? 'store' : 'truck'" class="size-3.5 shrink-0" />
            <x-admin.bi :en="__('sales_admin.type_'.$type, [], 'en')" :ar="__('sales_admin.type_'.$type, [], 'ar')" />
        </span>
    @endif
</div>
