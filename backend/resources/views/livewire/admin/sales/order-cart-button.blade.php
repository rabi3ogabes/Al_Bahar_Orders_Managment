<div>
@if ($can)
    {{-- On the New order page the cart is docked: open its sheet instead of the overlay. --}}
    <button type="button" x-data
            @click="document.querySelector('[data-docked-cart]') ? $dispatch('docked-cart-open') : Livewire.dispatch('open-order-cart')"
            @if ($variant === 'sidebar') class="nav-link w-full" @else class="btn-ghost btn-sm relative min-h-11" @endif
            aria-label="{{ __('neworder_admin.open_cart', ['count' => $count]) }}" aria-haspopup="dialog">
        <x-icon name="cart" class="{{ $variant === 'sidebar' ? 'size-[1.15rem] shrink-0' : 'size-4' }}" />
        <span class="{{ $variant === 'sidebar' ? 'flex-1 truncate text-start' : 'hidden font-medium md:inline' }}">{{ __('neworder_admin.panel_title') }}</span>
        @if ($count > 0)
            <span class="{{ $variant === 'sidebar' ? '' : 'absolute -end-0.5 -top-0.5 md:static' }} grid min-w-5 place-items-center rounded-full bg-brass-500 px-1.5 text-[0.7rem] font-semibold leading-5 text-ink-950 tabular-nums">{{ $count > 99 ? '99+' : $count }}</span>
        @endif
    </button>
@endif
</div>
