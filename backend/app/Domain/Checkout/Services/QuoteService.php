<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchDeliveryZone;
use App\Domain\Branches\Services\DeliveryZoneResolver;
use App\Domain\Branches\Services\SlotService;
use App\Domain\Checkout\Data\AppliedDiscount;
use App\Domain\Checkout\Data\FulfillmentPlan;
use App\Domain\Checkout\Data\Quote;
use App\Domain\Checkout\Data\QuoteLine;
use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Services\LoyaltyService;
use App\Domain\Marketing\Services\PromotionEngine;
use App\Domain\Orders\Models\Cart;
use App\Domain\Orders\Models\UserAddress;
use App\Domain\Pricing\Services\PriceResolver;
use App\Domain\Settings\Services\Settings;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Builds an authoritative, informational checkout quote. Nothing the client sends about prices, stock,
 * discounts or fees is trusted — everything is re-read from the database. A quote never reserves stock.
 *
 * Fee rules:
 *  - Delivery fee per fulfillment = branch-zone fee, waived when that fulfillment's subtotal reaches the zone's
 *    free-delivery threshold; + oversized surcharge if any oversized item; + express fee when requested & offered.
 *  - Zone min/max order limits are checked per fulfillment subtotal.
 *  - COD fee applies only to cash on delivery. Service fee from settings. Tax per configured rate (default 0%).
 */
class QuoteService
{
    public function __construct(
        private readonly FulfillmentAllocator $allocator,
        private readonly PriceResolver $prices,
        private readonly DeliveryZoneResolver $zones,
        private readonly SlotService $slots,
        private readonly PromotionEngine $promotions,
        private readonly LoyaltyService $loyalty,
        private readonly Settings $settings,
    ) {}

    /**
     * @param  array{fulfillment_type?: string, address_id?: ?string, branch_id?: ?string, payment_method?: ?string, coupon_code?: ?string,
     *               loyalty_points?: int, allow_split?: bool, express?: bool, pickup_slot_id?: ?string, pickup_date?: ?string,
     *               delivery_slot_id?: ?string, delivery_date?: ?string, latitude?: ?float, longitude?: ?float}  $o
     */
    public function quote(User $user, Cart $cart, array $o): Quote
    {
        $q = new Quote;
        $q->fulfillmentType = $o['fulfillment_type'] ?? $cart->fulfillment_type ?? 'delivery';
        $q->paymentMethod = $o['payment_method'] ?? null;
        $q->couponCode = array_key_exists('coupon_code', $o) ? ($o['coupon_code'] ?: null) : $cart->coupon_code;
        $cart->loadMissing(['items.variant.product.translations', 'items.variant.product.images', 'items.variant.images', 'items.variant.attributeValues.attribute']);

        if ($cart->items->isEmpty()) {
            $q->error('cart_empty');

            return $this->finalize($q, $user, $o);
        }

        // 1. Wanted quantities; drop inactive products with an explicit error.
        $wanted = [];
        foreach ($cart->items as $item) {
            $avail = $this->prices->availability($item->variant, null);
            if (! $avail->purchasable) {
                $q->error('item_unavailable', ['variant_id' => $item->product_variant_id, 'name' => $item->variant->product->trans('name')]);

                continue;
            }
            $wanted[$item->product_variant_id] = ($wanted[$item->product_variant_id] ?? 0) + $item->quantity;
        }

        // 2. Candidate branches.
        [$candidates, $zoneConfigs, $address] = $this->candidates($user, $cart, $o, $q);
        if ($candidates->isEmpty() || ! $wanted) {
            return $this->finalize($q, $user, $o);
        }

        // 3. Allocate units to branches.
        $preferred = $o['branch_id'] ?? $cart->branch_id;
        $alloc = $this->allocator->allocate($wanted, $candidates, $preferred, (bool) ($o['allow_split'] ?? true),
            isset($o['latitude']) ? (float) $o['latitude'] : $address?->latitude, isset($o['longitude']) ? (float) $o['longitude'] : $address?->longitude);
        foreach ($alloc['shortages'] as $vid => $missing) {
            $item = $cart->items->firstWhere('product_variant_id', $vid);
            $q->error('insufficient_stock', ['variant_id' => $vid, 'name' => $item?->variant->product->trans('name'), 'requested' => $wanted[$vid], 'available' => $wanted[$vid] - $missing]);
        }
        $q->isSplit = count($alloc['allocations']) > 1;

        // 4. Build priced lines per fulfillment.
        $branches = $candidates->keyBy('id');
        $variants = $cart->items->pluck('variant')->keyBy('id');
        foreach ($alloc['allocations'] as $branchId => $lines) {
            $branch = $branches[$branchId];
            $this->prices->prime($variants->only(array_keys($lines))->values(), $branchId);
            $planLines = [];
            foreach ($lines as $vid => $qty) {
                $price = $this->prices->resolve($variants[$vid], $branch);
                if (! $price) {
                    continue;
                }
                $planLines[] = new QuoteLine($vid.'|'.$branchId, $variants[$vid], $branch, $qty, $price);
            }
            $single = count($alloc['allocations']) === 1;
            $q->plans[] = new FulfillmentPlan($branch, $q->fulfillmentType, $planLines, $zoneConfigs[$branchId] ?? null, null,
                $single ? ($q->fulfillmentType === 'pickup' ? ($o['pickup_slot_id'] ?? null) : ($o['delivery_slot_id'] ?? null)) : null,
                $single ? ($q->fulfillmentType === 'pickup' ? ($o['pickup_date'] ?? null) : ($o['delivery_date'] ?? null)) : null,
                (bool) ($o['express'] ?? false), $alloc['reason']);
        }
        if ($q->isSplit) {
            $q->warning('split_fulfillment', ['branches' => count($q->plans)]);
            if (! empty($o['pickup_slot_id']) || ! empty($o['delivery_slot_id'])) {
                $q->warning('slot_ignored_for_split');
            }
        }

        // 5. Price-change warnings against the price the customer saw when adding to cart.
        foreach ($cart->items as $item) {
            $line = $q->lines()->first(fn (QuoteLine $l) => $l->variant->id === $item->product_variant_id);
            if ($line && $item->price_at_add !== null && ! Money::of($item->price_at_add)->equals($line->price->final())) {
                $q->warning('price_changed', ['variant_id' => $item->product_variant_id, 'name' => $item->variant->product->trans('name'),
                    'from' => Money::of($item->price_at_add)->jsonSerialize(), 'to' => $line->price->final()->jsonSerialize()]);
            }
        }

        // 6. Delivery fees & zone limits per fulfillment.
        foreach ($q->plans as $plan) {
            $this->applyFulfillmentFees($plan, $q);
            $this->validateSlot($plan, $q);
        }

        // 7. Promotions, 8. loyalty, 9. fees & tax, 10. payment methods.
        $promo = $this->promotions->evaluate($q->lines()->all(), $user, $q->couponCode, $q->deliveryFeeTotal());
        $q->discounts = $promo['discounts'];
        if ($q->couponCode && $promo['coupon_error']) {
            $q->warning($promo['coupon_error'], ['code' => $q->couponCode]);
        }
        $afterPromotions = $q->subtotal()->minus($q->itemDiscountTotal());
        $requestedPoints = (int) ($o['loyalty_points'] ?? $cart->loyalty_points_to_redeem ?? 0);
        $maxPoints = $this->loyalty->maxRedeemable($user, $afterPromotions);
        $q->loyaltyPoints = max(0, min($requestedPoints, $maxPoints));
        $q->loyaltyDiscount = $this->loyalty->valueOf($q->loyaltyPoints);
        $q->loyaltyBalance = $this->loyalty->enabled() ? $this->loyalty->account($user)->balance : 0;
        $q->loyaltyMax = $maxPoints;
        if ($requestedPoints > $maxPoints) {
            $q->warning('loyalty_capped', ['max' => $maxPoints]);
        }

        $q->serviceFee = Money::of((string) $this->settings->get('checkout.service_fee', '0.00'));
        $taxRate = (string) $this->settings->get('checkout.tax_rate_percent', '0');
        $q->taxRate = $taxRate;
        foreach ($q->lines() as $line) {
            $line->tax = $line->subtotal()->minus($line->discount)->percent($taxRate);
        }
        $this->paymentMethods($q, $user);
        $q->codFee = $q->paymentMethod === 'cod' ? Money::of((string) $this->settings->get('checkout.cod_fee', '0.00')) : Money::zero();

        return $this->finalize($q, $user, $o);
    }

    /** @return array{0: Collection<int, Branch>, 1: array<string, BranchDeliveryZone>, 2: ?UserAddress} */
    private function candidates(User $user, Cart $cart, array $o, Quote $q): array
    {
        if ($q->fulfillmentType === 'pickup') {
            $branchId = $o['branch_id'] ?? $cart->branch_id;
            $branch = $branchId ? Branch::active()->where('pickup_enabled', true)->with(['hours', 'holidays'])->find($branchId) : null;
            if (! $branch) {
                $q->error('pickup_branch_required');

                return [collect(), [], null];
            }
            $q->pickupBranch = $branch;
            $others = ($o['allow_split'] ?? true)
                ? Branch::active()->where('pickup_enabled', true)->whereKeyNot($branch->id)->orderBy('sort_order')->get()
                : collect();

            return [collect([$branch])->concat($others), [], null];
        }

        $addressId = $o['address_id'] ?? $cart->address_id;
        $address = $addressId ? UserAddress::where('user_id', $user->id)->with('zone')->find($addressId) : null;
        if (! $address) {
            $q->error('address_required');

            return [collect(), [], null];
        }
        $q->address = $address;
        $zone = $this->zones->resolve($address->delivery_zone_id, $address->zone_number, $address->latitude, $address->longitude);
        if (! $zone) {
            $q->error('delivery_unavailable_area');

            return [collect(), [], $address];
        }
        $q->zone = $zone;
        $configs = $this->zones->servingBranches($zone);
        if ($configs->isEmpty()) {
            $q->error('delivery_unavailable_area');
        }
        $branches = $configs->map(function (BranchDeliveryZone $c) {
            $c->branch->setAttribute('zone_priority', $c->priority);

            return $c->branch;
        });

        return [$branches, $configs->keyBy('branch_id')->all(), $address];
    }

    private function applyFulfillmentFees(FulfillmentPlan $plan, Quote $q): void
    {
        if ($plan->type !== 'delivery' || ! $plan->zoneConfig) {
            return;
        }
        $cfg = $plan->zoneConfig;
        $subtotal = $plan->subtotal();
        if ($cfg->min_order_amount !== null && $subtotal->lessThan(Money::of($cfg->min_order_amount))) {
            $q->error('below_min_order', ['branch' => $plan->branch->localized('name'), 'minimum' => Money::of($cfg->min_order_amount)->jsonSerialize(),
                'shortfall' => Money::of($cfg->min_order_amount)->minus($subtotal)->jsonSerialize()]);
        }
        if ($cfg->max_order_amount !== null && $subtotal->greaterThan(Money::of($cfg->max_order_amount))) {
            $q->error('above_max_order', ['branch' => $plan->branch->localized('name'), 'maximum' => Money::of($cfg->max_order_amount)->jsonSerialize()]);
        }
        $fee = Money::of($cfg->delivery_fee);
        if ($cfg->free_delivery_threshold !== null && $subtotal->greaterThanOrEqual(Money::of($cfg->free_delivery_threshold))) {
            $fee = Money::zero();
        } elseif ($cfg->free_delivery_threshold !== null) {
            $q->hints[] = ['code' => 'free_delivery_progress', 'branch_id' => $plan->branch->id,
                'remaining' => Money::of($cfg->free_delivery_threshold)->minus($subtotal)->jsonSerialize()];
        }
        if (collect($plan->lines)->contains(fn (QuoteLine $l) => $l->isOversized())) {
            $fee = $fee->plus(Money::of($cfg->oversized_surcharge ?? '0'));
        }
        if ($plan->express) {
            $cfg->express_enabled && $cfg->express_fee !== null
                ? $fee = $fee->plus(Money::of($cfg->express_fee))
                : $q->warning('express_unavailable', ['branch' => $plan->branch->localized('name')]);
        }
        $plan->deliveryFee = $fee;
    }

    private function validateSlot(FulfillmentPlan $plan, Quote $q): void
    {
        if (! $plan->slotId || ! $plan->slotDate) {
            return;
        }
        $slot = collect($this->slots->available($plan->branch, $plan->type, $plan->slotDate))->firstWhere('id', $plan->slotId);
        if (! $slot || $slot['is_full']) {
            $q->error('slot_unavailable', ['branch' => $plan->branch->localized('name')]);
        } else {
            $q->slot = $slot;
        }
    }

    private function paymentMethods(Quote $q, User $user): void
    {
        $grand = $q->grandTotalBeforeCod();
        $branches = collect($q->plans)->pluck('branch');
        $sadad = (bool) config('platform.payments.sadad.enabled');
        $codReason = null;
        if ($q->fulfillmentType !== 'delivery') {
            $codReason = 'delivery_only';
        } elseif ($branches->contains(fn ($b) => ! $b->cod_enabled) || collect($q->plans)->contains(fn ($p) => $p->zoneConfig && ! $p->zoneConfig->cod_enabled)) {
            $codReason = 'not_offered_in_area';
        } else {
            foreach ($branches as $b) {
                if (($b->cod_min_order && $grand->lessThan(Money::of($b->cod_min_order))) || ($b->cod_max_order && $grand->greaterThan(Money::of($b->cod_max_order)))) {
                    $codReason = 'outside_cod_limits';
                }
            }
        }
        $pickupReason = $q->fulfillmentType !== 'pickup' ? 'pickup_only' : ($branches->contains(fn ($b) => ! $b->pay_at_pickup_enabled) ? 'not_offered_at_branch' : null);

        $q->paymentMethods = [
            ['code' => 'sadad', 'available' => $sadad, 'reason' => $sadad ? null : 'temporarily_unavailable'],
            ['code' => 'cod', 'available' => $codReason === null, 'reason' => $codReason],
            ['code' => 'pay_at_pickup', 'available' => $pickupReason === null, 'reason' => $pickupReason],
        ];
        if ($q->paymentMethod && ! collect($q->paymentMethods)->firstWhere('code', $q->paymentMethod)['available']) {
            $q->error('payment_method_unavailable', ['method' => $q->paymentMethod]);
        }
    }

    private function finalize(Quote $q, User $user, array $o): Quote
    {
        $q->pointsToEarn = $this->loyalty->pointsToEarn($q->grandTotal()->minus($q->deliveryFeeTotal()->minus($q->deliveryDiscountTotal())));
        if (! $q->paymentMethod) {
            $q->warning('payment_method_required');
        }
        $q->expiresAt = now()->addMinutes(15);
        $q->hash = $q->computeHash();

        return $q;
    }
}
