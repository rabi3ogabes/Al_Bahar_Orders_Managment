{{-- What the branch did to an order line while preparing it (reduced, removed, swapped out, a replacement), and the assembly the customer bought for it. $it is an OrderItem. --}}
@if ($it->quantity > 0 && \App\Domain\Checkout\Support\Assembly::placedFee($it))
    <span class="badge-brass !text-[0.65rem]">{{ __('sales_admin.badge_assembly', ['amount' => \App\Support\Money::of((string) $it->assembly_total)->format(app()->getLocale())]) }}</span>
@endif
@if ($it->adjustment_kind)
    @switch($it->adjustment_kind)
        @case('reduced')<span class="badge-warning !text-[0.65rem]">{{ __('adjust_admin.badge_reduced', ['n' => $it->original_quantity]) }}</span>@break
        @case('removed')<span class="badge-danger !text-[0.65rem]">{{ __('adjust_admin.badge_removed') }}</span>@break
        @case('replaced')<span class="badge-danger !text-[0.65rem]">{{ __('adjust_admin.badge_replaced') }}</span>@break
        @case('replacement')<span class="badge-brass !text-[0.65rem]">{{ __('adjust_admin.badge_replacement') }}</span>@break
    @endswitch
@endif
