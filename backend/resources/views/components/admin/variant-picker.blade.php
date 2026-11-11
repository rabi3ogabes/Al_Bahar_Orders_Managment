@props(['results', 'placeholder' => null, 'label' => null])
{{-- Pair with the WithVariantPicker trait. Enter picks the exact SKU/barcode (scanner friendly). --}}
<div {{ $attributes->merge(['class' => 'relative']) }} x-data="{ focused: false }">
    @if ($label)<label for="variant-picker" class="field-label">{{ $label }}</label>@endif
    <div class="relative" dir="ltr">
        <x-icon name="qr" class="pointer-events-none absolute top-1/2 start-3.5 size-4 -translate-y-1/2 text-ink-400" />
        <input id="variant-picker" type="search" autocomplete="off" dir="ltr"
               wire:model.live.debounce.250ms="variantSearch" wire:keydown.enter.prevent="pickVariantByCode"
               @focus="focused = true" @click.outside="focused = false" @keydown.escape="focused = false"
               placeholder="{{ $placeholder ?? __('inventory_admin.scan_or_search') }}" class="input ps-10 font-mono">
        <x-icon name="refresh" wire:loading wire:target="variantSearch,pickVariantByCode" class="absolute top-1/2 end-3.5 size-4 -translate-y-1/2 animate-spin text-brass-500" />
    </div>
    @if ($results->isNotEmpty())
        <ul x-show="focused" x-transition.opacity class="absolute inset-x-0 top-full z-30 mt-1.5 max-h-80 overflow-y-auto rounded-xl border border-sand-200 bg-white p-1 shadow-[var(--shadow-lift)] dark:border-ink-700 dark:bg-ink-900" role="listbox">
            @foreach ($results as $v)
                <li wire:key="vp-{{ $v->id }}">
                    <button type="button" wire:click="pickVariant('{{ $v->id }}')" @click="focused = false" class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-start text-sm hover:bg-sand-50 focus:bg-sand-50 dark:hover:bg-ink-800 dark:focus:bg-ink-800">
                        <x-admin.variant :variant="$v" class="flex-1" />
                        <x-icon name="plus" class="size-4 shrink-0 text-brass-500" />
                    </button>
                </li>
            @endforeach
        </ul>
    @endif
</div>
