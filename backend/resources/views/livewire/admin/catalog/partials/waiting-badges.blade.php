{{-- Customers who asked to be told about this product: the demand signal (docs/guides/ALERTS_AFTER_DELIVERY_AND_ROOMS.md). Nothing is shown when nobody waits. --}}
@if (($waiting['back_in_stock'] ?? 0) > 0)
    <span class="badge-brass tabular-nums" title="{{ __('alerts_admin.waiting_stock_hint') }}"><x-icon name="bell" class="size-3.5" />{{ trans_choice('alerts_admin.waiting_stock', $waiting['back_in_stock'], ['n' => number_format($waiting['back_in_stock'])]) }}</span>
@endif
@if (($waiting['price_drop'] ?? 0) > 0)
    <span class="badge-neutral tabular-nums" title="{{ __('alerts_admin.waiting_price_hint') }}"><x-icon name="tag" class="size-3.5" />{{ trans_choice('alerts_admin.waiting_price', $waiting['price_drop'], ['n' => number_format($waiting['price_drop'])]) }}</span>
@endif
