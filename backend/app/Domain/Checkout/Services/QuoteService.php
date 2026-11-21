<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchDeliveryZone;
use App\Domain\Branches\Services\DeliveryZoneResolver;
use App\Domain\Branches\Services\SlotService;
use App\Domain\Checkout\Data\FulfillmentPlan;
use App\Domain\Checkout\Data\Quote;
use App\Domain\Checkout\Data\QuoteLine;
use App\Domain\Checkout\Support\Assembly;
use App\Domain\Deals\Services\DealEngine;
use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Services\LoyaltyService;
use App\Domain\Marketing\Services\PromotionEngine;
use App\Domain\Orders\Models\Cart;
use App\Domain\Orders\Models\UserAddress;
use App\Domain\Pricing\Services\PriceResolver;
use App\Domain\Settings\Services\Settings;
use App\Support\AlternatePhone;
use App\Support\Money;
use App\Support\Phone;
use Illuminate\Support\Collection;

/**
 * Builds an authoritative, informational checkout quote. Nothing the client sends about prices, stock,
 * discounts or fees is trusted — everything is re-read from the database. A quote never reserves stock.
 *
 * Fee rules (docs/api/CHECKOUT_FEES_V2.md):
 *  - Delivery fee per delivery fulfillment = branch-zone fee + oversized surcharge if any oversized item + the extra delivery fee of the products in
 *    that fulfillment (ExtraDeliveryFee: per unit or once per product) + express fee when requested & offered. Nothing but a free-delivery coupon
 *    waives it (PromotionEngine, after the fees). Pickup has no delivery fee.
 *  - The zone's maximum order amount is checked per fulfillment subtotal. There is no minimum order.
 *  - Cash on delivery costs nothing extra. Service fee from settings. Tax per configured rate (default 0%).
 *  - Splitting an order across branches is possible only while the admin setting checkout.show_split_shipment is on; when it is off the
 *    client's allow_split is ignored (no split). The same setting decides whether clients show the switch (quote.split_choice).
 *  - A delivery order may carry the customer's second number for the driver (alternate_phone / no_alternate_phone, see alternatePhone()),
 *    unless the dashboard switch (AlternatePhone::enabled) is off.
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
        private readonly DealEngine $deals,
        private readonly DeliveryPromise $promise,
    ) {}

    /**
     * @param  array{fulfillment_type?: string, address_id?: ?string, branch_id?: ?string, payment_method?: ?string, coupon_code?: ?string,
     *               loyalty_points?: int, allow_split?: bool, express?: bool, pickup_slot_id?: ?string, pickup_date?: ?string,
     *               delivery_slot_id?: ?string, delivery_date?: ?string, latitude?: ?float, longitude?: ?float, restrict_branch_ids?: list<string>,
     *               alternate_phone?: ?string, no_alternate_phone?: bool, apply_deals?: bool, with_promise?: bool}  $o
     */
    public function quote(User $user, Cart $cart, array $o): Quote
    {
        $q = new Quote;
        $q->fulfillmentType = $o['fulfillment_type'] ?? $cart->fulfillment_type ?? 'delivery';
        $q->paymentMethod = $o['payment_method'] ?? null;
        $q->couponCode = array_key_exists('coupon_code', $o) ? ($o['coupon_code'] ?: null) : $cart->coupon_code;
        $this->alternatePhone($user, $q, $o);
        // The admin switch wins over whatever the client sent: with it off an order is never split.
        $o['allow_split'] = $this->splitEnabled() && (bool) ($o['allow_split'] ?? true);
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
        // Staff-assisted orders: server-derived branch scope (never client input) limits which branches may fulfil.
        if (isset($o['restrict_branch_ids']) && $candidates->isNotEmpty()) {
            $candidates = $candidates->whereIn('id', $o['restrict_branch_ids'])->values();
            if ($candidates->isEmpty()) {
                $q->error('branch_not_allowed');
            }
        }
        if ($candidates->isEmpty() || ! $wanted) {
            return $this->finalize($q, $user, $o);
        }

        // 3. Allocate units to branches.
        $preferred = $o['branch_id'] ?? $cart->branch_id;
        $alloc = $this->allocator->allocate($wanted, $candidates, $preferred, $o['allow_split'],
            isset($o['latitude']) ? (float) $o['latitude'] : $address?->latitude, isset($o['longitude']) ? (float) $o['longitude'] : $address?->longitude);
        foreach ($alloc['shortages'] as $vid => $missing) {
            $item = $cart->items->firstWhere('product_variant_id', $vid);
            $q->error('insufficient_stock', ['variant_id' => $vid, 'name' => $item?->variant->product->trans('name'), 'requested' => $wanted[$vid], 'available' => $wanted[$vid] - $missing]);
        }
        $q->isSplit = count($alloc['allocations']) > 1;
        $q->splitChoice = $this->splitEnabled() && $alloc['stocked_branches'] > 1;

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
        $this->applyAssembly($q, $cart);
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

        // 6. Delivery fees & zone limits per fulfillment, the slot, and the date promised for each parcel.
        foreach ($q->plans as $plan) {
            $this->applyFulfillmentFees($plan, $q);
            $this->validateSlot($plan, $q);
        }
        if ($this->promise->enabled() && ($o['with_promise'] ?? true)) {
            $this->promiseDates($q);
        }

        // 7. Deals first (units that get a deal price are not discounted twice), then promotions; 8. loyalty, 9. fees & tax, 10. payment methods.
        // A staff-made order (`apply_deals` false, set by StaffOrderService, never by a client) does not get deal prices.
        $dealDiscounts = ($o['apply_deals'] ?? true) ? $this->deals->apply($q->lines()->all(), $user) : [];
        $promo = $this->promotions->evaluate($q->lines()->all(), $user, $q->couponCode, $q->deliveryFeeTotal());
        $q->discounts = array_merge($dealDiscounts, $promo['discounts']);
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

        return $this->finalize($q, $user, $o);
    }

    /** The admin setting "Settings → Checkout → Show the split shipment switch" (default on). */
    private function splitEnabled(): bool
    {
        return (bool) $this->settings->get('checkout.show_split_shipment', true);
    }

    /**
     * The customer's second number, for the driver (delivery orders only; pickup never asks).
     *  - alternate_phone given: must be a Qatar mobile number other than the main one, otherwise a plain-words error.
     *  - no_alternate_phone ("I don't have another number"): none, whatever is saved on the profile.
     *  - a current client always sends one of the two; if it sent neither a value nor the tick, the quote says what is missing.
     *  - an older client sends neither key: the number saved on the profile (if any) is used and nothing is required of it.
     */
    private function alternatePhone(User $user, Quote $q, array $o): void
    {
        if ($q->fulfillmentType !== 'delivery' || ! AlternatePhone::enabled()) {
            return; // pickup never asks; with the dashboard switch off nobody asks, nothing is required and nothing is saved on the order
        }
        $asked = array_key_exists('alternate_phone', $o) || array_key_exists('no_alternate_phone', $o);
        if (! $asked) {
            $saved = (string) $user->alternate_phone;
            $q->alternatePhone = $saved !== '' && ! Phone::same($saved, $user->phone) ? $saved : null;

            return;
        }
        [$phone, $error] = AlternatePhone::check($o['alternate_phone'] ?? null, $user->phone);
        if ($error !== null) {
            $q->error($error);
        } elseif ($phone !== null) {
            $q->alternatePhone = $phone;
            $q->alternatePhoneGiven = true;
        } elseif (empty($o['no_alternate_phone'])) {
            $q->error('alternate_phone_required');
        }
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
            $others = $o['allow_split']
                ? Branch::active()->where('pickup_enabled', true)->whereKeyNot($branch->id)->orderBy('sort_order')->get()
                : collect();

            return [collect([$branch])->concat($others), [], null];
        }

        $addressId = $o['address_id'] ?? $cart->address_id;
        $address = $addressId ? UserAddress::where('user_id', $user->id)->with('zone')->whereKey($addressId)->first() : null;
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
        $branches = $configs->map(function (BranchDeliveryZone $c): Branch {
            /** @var Branch $branch the FK is NOT NULL */
            $branch = $c->branch;
            $branch->setAttribute('zone_priority', $c->priority);

            return $branch;
        });

        return [$branches, $configs->keyBy('branch_id')->all(), $address];
    }

    private function applyFulfillmentFees(FulfillmentPlan $plan, Quote $q): void
    {
        if ($plan->type !== 'delivery' || ! $plan->zoneConfig) {
            return;
        }
        $cfg = $plan->zoneConfig;
        // There is no minimum order any more (docs/api/CHECKOUT_FEES_V2.md section 2); only the zone's maximum is checked.
        if ($cfg->max_order_amount !== null && $plan->subtotal()->greaterThan(Money::of($cfg->max_order_amount))) {
            $q->error('above_max_order', ['branch' => $plan->branch->localized('name'), 'maximum' => Money::of($cfg->max_order_amount)->jsonSerialize()]);
        }
        // Every delivery pays the zone fee, whatever the basket is worth. The only way to waive it is a free-delivery coupon:
        // PromotionEngine zeroes it later through a discount line, so the quote shows the fee and what the code took off.
        // delivery fee = zone fee + oversized surcharge + the extra delivery fees of the products in this fulfillment + express fee.
        $fee = Money::of($cfg->delivery_fee);
        if (collect($plan->lines)->contains(fn (QuoteLine $l) => $l->isOversized())) {
            $fee = $fee->plus(Money::of($cfg->oversized_surcharge ?? '0'));
        }
        $plan->deliveryExtras = ExtraDeliveryFee::forLines($plan->lines);
        $fee = $fee->plus($plan->extraDeliveryFee());
        if ($plan->express) {
            $cfg->express_enabled && $cfg->express_fee !== null
                ? $fee = $fee->plus(Money::of($cfg->express_fee))
                : $q->warning('express_unavailable', ['branch' => $plan->branch->localized('name')]);
        }
        $plan->deliveryFee = $fee;
    }

    /**
     * The assembly the customer ticked in the cart, as the quote charges it: delivery orders only, only for products that offer it right now. A tick that
     * can no longer be honoured (the feature was switched off, the fee removed) is not charged and the customer is told in the quote.
     */
    private function applyAssembly(Quote $q, Cart $cart): void
    {
        if ($q->fulfillmentType !== 'delivery') {
            return; // assembly is done at the door: a pickup never pays for it, and the cart keeps the tick for when they switch back
        }
        $ticked = $cart->items->where('assembly', true)->keyBy('product_variant_id');
        if ($ticked->isEmpty()) {
            return;
        }
        foreach ($q->lines() as $line) {
            if ($ticked->has($line->variant->id)) {
                $line->assemblyFee = Assembly::unitFee($line->variant->product);
            }
        }
        foreach ($ticked as $variantId => $item) {
            if (! Assembly::offered($item->variant->product)) {
                $q->warning('assembly_unavailable', ['variant_id' => $variantId, 'name' => $item->variant->product->trans('name')]);
            }
        }
    }

    /** The date promised for every parcel and for the whole basket (informational: it is not part of the quote hash). */
    private function promiseDates(Quote $q): void
    {
        $parcels = [];
        foreach ($q->plans as $plan) {
            $chosen = $plan->slotInfo !== null && $plan->slotId !== null && $plan->slotDate !== null
                ? ['id' => $plan->slotId, 'date' => $plan->slotDate, 'starts_at' => $plan->slotInfo['starts_at'], 'ends_at' => $plan->slotInfo['ends_at']] : null;
            $plan->promise = $this->promise->parcel($plan->branch, $plan->type, $this->promiseLines($plan), $plan->zoneConfig, null, null, $chosen);
            $parcels[] = $plan->promise;
        }
        $q->promise = ['enabled' => true, 'fulfillment_type' => $q->fulfillmentType] + $this->promise->summarize($parcels, $q->fulfillmentType)
            + ['location_known' => true, 'parcels' => $parcels];
    }

    /** @return list<array{variant_id: string, quantity: int, lead_days: int, assembly: bool}> the parcel's lines as DeliveryPromise reads them */
    private function promiseLines(FulfillmentPlan $plan): array
    {
        return array_map(fn (QuoteLine $l) => ['variant_id' => $l->variant->id, 'quantity' => $l->quantity, 'lead_days' => (int) $l->variant->product->lead_time_days,
            'assembly' => $l->assemblyFee !== null], $plan->lines);
    }

    private function validateSlot(FulfillmentPlan $plan, Quote $q): void
    {
        // The first day these items can be scheduled on (their lead time); the checkout refuses an earlier slot and the pickers start there.
        $plan->earliestDate = $this->promise->earliestDate($this->promiseLines($plan), $plan->type);
        // Delivery on a single-branch order needs a slot whenever that branch publishes delivery slots.
        // (Split orders can't share one slot; each branch confirms its own time.)
        $plan->slotRequired = $plan->type === 'delivery' && ! $q->isSplit && $this->slots->hasSlots($plan->branch, 'delivery');
        if (! $plan->slotId || ! $plan->slotDate) {
            if ($plan->slotRequired) {
                $q->error('slot_required', ['branch' => $plan->branch->localized('name')]);
            }

            return;
        }
        $slot = collect($this->slots->available($plan->branch, $plan->type, $plan->slotDate))->firstWhere('id', $plan->slotId);
        if (! $slot || $slot['is_full']) {
            $q->error('slot_unavailable', ['branch' => $plan->branch->localized('name')]);
        } elseif ($plan->slotDate < $plan->earliestDate) {
            // The items need more days than that (made to order, or assembly): the promise says so and the picker starts later; a stale screen is told plainly.
            $q->error('slot_too_soon', ['branch' => $plan->branch->localized('name'), 'date' => $plan->earliestDate]);
        } else {
            $q->slot = $slot;
            $plan->slotInfo = ['starts_at' => $slot['starts_at'], 'ends_at' => $slot['ends_at']];
        }
    }

    private function paymentMethods(Quote $q, User $user): void
    {
        $grand = $q->grandTotal();
        $branches = collect($q->plans)->pluck('branch');
        $sadad = (bool) config('platform.payments.sadad.enabled');
        $codReason = null;
        $codLimits = null;
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
            if ($codReason !== null) {
                // The numbers behind the reason, so an app can say "up to QAR 5,000" instead of "not available": the strictest limit of the branches in this order.
                $mins = $branches->filter(fn ($b) => $b->cod_min_order && Money::of($b->cod_min_order)->isPositive())->map(fn ($b) => Money::of($b->cod_min_order));
                $maxes = $branches->filter(fn ($b) => $b->cod_max_order)->map(fn ($b) => Money::of($b->cod_max_order));
                $codLimits = ['min' => $mins->isEmpty() ? null : $mins->reduce(fn (?Money $c, Money $m) => $c === null ? $m : $c->max($m)),
                    'max' => $maxes->isEmpty() ? null : $maxes->reduce(fn (?Money $c, Money $m) => $c === null ? $m : $c->min($m))];
                $codLimits = array_map(fn (?Money $m) => $m?->jsonSerialize(), $codLimits);
            }
        }
        $pickupReason = $q->fulfillmentType !== 'pickup' ? 'pickup_only' : ($branches->contains(fn ($b) => ! $b->pay_at_pickup_enabled) ? 'not_offered_at_branch' : null);

        $q->paymentMethods = [
            ['code' => 'sadad', 'available' => $sadad, 'reason' => $sadad ? null : 'temporarily_unavailable'],
            ['code' => 'cod', 'available' => $codReason === null, 'reason' => $codReason] + ($codLimits !== null ? ['limits' => $codLimits] : []),
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
        $this->deals->remember($q); // what the deals promised, so placing it can tell "a deal changed" from any other change

        return $q;
    }
}
