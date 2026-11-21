<?php

namespace App\Domain\Checkout\Data;

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\DeliveryZone;
use App\Domain\Orders\Models\UserAddress;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Collection;

/** Result of QuoteService. `hash` binds the exact totals the customer is shown and must confirm. */
final class Quote
{
    public string $fulfillmentType = 'delivery';

    public ?string $paymentMethod = null;

    public ?string $couponCode = null;

    /** @var list<FulfillmentPlan> */
    public array $plans = [];

    /** @var list<AppliedDiscount> */
    public array $discounts = [];

    public bool $isSplit = false;

    /** The clients may show the "allow split shipment" switch: the admin setting is on AND more than one active branch could supply part of this cart. */
    public bool $splitChoice = false;

    /** The customer's second number for THIS delivery order (E.164), once validated; null for pickup, "I don't have another number" or none. */
    public ?string $alternatePhone = null;

    /** The customer typed it for this order (as opposed to the saved profile number being used for a client that does not send the field). */
    public bool $alternatePhoneGiven = false;

    public ?UserAddress $address = null;

    public ?DeliveryZone $zone = null;

    public ?Branch $pickupBranch = null;

    public ?array $slot = null;

    public int $loyaltyPoints = 0;

    public int $loyaltyBalance = 0;

    public int $loyaltyMax = 0;

    public int $pointsToEarn = 0;

    public Money $loyaltyDiscount;

    public Money $serviceFee;

    public string $taxRate = '0';

    public array $paymentMethods = [];

    public array $errors = [];

    public array $warnings = [];

    public array $hints = [];

    /** The dates promised for the whole basket (DeliveryPromise::basket), set by the quote; informational, never part of the quote hash. @var array<string, mixed>|null */
    public ?array $promise = null;

    public ?string $hash = null;

    public $expiresAt = null;

    public function __construct()
    {
        $this->loyaltyDiscount = Money::zero();
        $this->serviceFee = Money::zero();
    }

    public function error(string $code, array $context = []): void
    {
        $this->errors[] = ['code' => $code, 'message' => __('checkout.'.$code, $this->flat($context))] + $context;
    }

    public function warning(string $code, array $context = []): void
    {
        $this->warnings[] = ['code' => $code, 'message' => __('checkout.'.$code, $this->flat($context))] + $context;
    }

    public function canPlace(): bool
    {
        return $this->errors === [] && $this->plans !== [] && $this->paymentMethod !== null;
    }

    /** @return Collection<int, QuoteLine> */
    public function lines(): Collection
    {
        return collect($this->plans)->flatMap(fn (FulfillmentPlan $p) => $p->lines)->values();
    }

    public function subtotal(): Money
    {
        return $this->lines()->reduce(fn (Money $c, QuoteLine $l) => $c->plus($l->subtotal()), Money::zero());
    }

    public function itemDiscountTotal(): Money
    {
        return $this->lines()->reduce(fn (Money $c, QuoteLine $l) => $c->plus($l->discount), Money::zero());
    }

    public function dealSavingsTotal(): Money
    {
        return $this->lines()->reduce(fn (Money $c, QuoteLine $l) => $c->plus($l->dealSavings()), Money::zero());
    }

    public function deliveryFeeTotal(): Money
    {
        return array_reduce($this->plans, fn (Money $c, FulfillmentPlan $p) => $c->plus($p->deliveryFee), Money::zero());
    }

    public function deliveryDiscountTotal(): Money
    {
        $free = collect($this->discounts)->first(fn (AppliedDiscount $d) => $d->freeDelivery);

        return $free ? $this->deliveryFeeTotal() : Money::zero();
    }

    /** The part of the delivery fee that comes from products with an extra delivery fee (already inside `deliveryFeeTotal()`). */
    public function extraDeliveryFeeTotal(): Money
    {
        return array_reduce($this->plans, fn (Money $c, FulfillmentPlan $p) => $c->plus($p->extraDeliveryFee()), Money::zero());
    }

    /** @return list<DeliveryExtra> every product's extra delivery fee in this quote, fulfillment by fulfillment */
    public function deliveryExtras(): array
    {
        return array_values(array_merge(...array_map(fn (FulfillmentPlan $p) => $p->deliveryExtras, $this->plans)));
    }

    /** What the lines cost for assembly and installation (delivery orders only, a flat service charge: no discount, no tax). */
    public function assemblyTotal(): Money
    {
        return array_reduce($this->plans, fn (Money $c, FulfillmentPlan $p) => $c->plus($p->assemblyTotal()), Money::zero());
    }

    public function taxTotal(): Money
    {
        return $this->lines()->reduce(fn (Money $c, QuoteLine $l) => $c->plus($l->tax), Money::zero());
    }

    public function discountTotal(): Money
    {
        return $this->itemDiscountTotal()->plus($this->deliveryDiscountTotal());
    }

    /** Cash on delivery costs nothing extra (docs/api/CHECKOUT_FEES_V2.md section 1): the total is the same for every payment method. */
    public function grandTotal(): Money
    {
        $total = $this->subtotal()->minus($this->itemDiscountTotal())->plus($this->deliveryFeeTotal())->minus($this->deliveryDiscountTotal())
            ->plus($this->assemblyTotal())->plus($this->serviceFee)->plus($this->taxTotal())->minus($this->loyaltyDiscount);

        return $total->isNegative() ? Money::zero() : $total;
    }

    public function computeHash(): string
    {
        $canonical = [
            'type' => $this->fulfillmentType,
            'pay' => $this->paymentMethod,
            'coupon' => $this->couponCode ? strtoupper($this->couponCode) : null,
            'points' => $this->loyaltyPoints,
            'plans' => array_map(fn (FulfillmentPlan $p) => [
                'b' => $p->branch->id, 'fee' => $p->deliveryFee->minor, 'slot' => [$p->slotId, $p->slotDate], 'x' => $p->express,
                // The assembly fee joins a line's entry only when the customer chose it, so a quote without assembly keeps the hash it always had.
                'l' => array_map(fn (QuoteLine $l) => array_merge([$l->variant->id, $l->quantity, $l->price->final()->minor, $l->discount->minor, $l->tax->minor, $l->deal?->deal->id, $l->deal?->units ?? 0],
                    $l->assemblyFee !== null ? [$l->assemblyFee->minor] : []), $p->lines),
            ], $this->plans),
            'disc' => array_map(fn (AppliedDiscount $d) => [$d->sourceId(), $d->amount->minor, $d->deliveryDiscount->minor], $this->discounts),
            'total' => $this->grandTotal()->minor,
            'address' => $this->address?->id,
        ];

        return hash('sha256', json_encode($canonical));
    }

    public function toArray(): array
    {
        $line = fn (QuoteLine $l) => [
            'variant_id' => $l->variant->id,
            'product_id' => $l->variant->product_id,
            'branch_id' => $l->branch->id,
            'name' => $l->variant->product->trans('name'),
            'variant_name' => $l->variant->displayName(),
            'image_url' => $l->variant->product->images->first()?->url(320),
            'quantity' => $l->quantity,
            'unit_price' => $l->price->toArray(),
            'line_subtotal' => $l->subtotal()->jsonSerialize(),
            'discount' => $l->discount->jsonSerialize(),
            'line_total' => $l->subtotal()->minus($l->discount)->jsonSerialize(),
            // The deal that covers this product: how many units get the deal price and, when not all, why (docs/api/DEALS.md section 5). Null = no deal.
            'deal' => $l->deal?->toArray(),
            // The assembly the customer chose for this line: the fee per unit and what the line owes for it. Null = none.
            'assembly' => $l->assemblyFee !== null ? ['fee_each' => $l->assemblyFee->jsonSerialize(), 'amount' => $l->assemblyTotal()->jsonSerialize()] : null,
        ];

        return [
            'quote_hash' => $this->hash,
            'currency' => 'QAR',
            'fulfillment_type' => $this->fulfillmentType,
            'payment_method' => $this->paymentMethod,
            'is_split' => $this->isSplit,
            'split_choice' => $this->splitChoice,
            'fulfillments' => array_map(fn (FulfillmentPlan $p) => [
                'branch' => ['id' => $p->branch->id, 'name' => $p->branch->localized('name'), 'area' => $p->branch->area],
                'type' => $p->type,
                'items' => array_map($line, $p->lines),
                'subtotal' => $p->subtotal()->jsonSerialize(),
                'delivery_fee' => $p->deliveryFee->jsonSerialize(),
                'extra_delivery_fee' => $p->extraDeliveryFee()->jsonSerialize(),
                'assembly_fee' => $p->assemblyTotal()->jsonSerialize(),
                'delivery_extras' => array_map(fn (DeliveryExtra $e) => $e->toArray(), $p->deliveryExtras),
                'eta_minutes' => $p->zoneConfig?->estimated_minutes,
                'slot' => $p->slotId ? ['id' => $p->slotId, 'date' => $p->slotDate] + ($p->slotInfo ?? []) : null,
                'slot_required' => $p->slotRequired,
                'earliest_date' => $p->earliestDate !== '' ? $p->earliestDate : null,
                'allocation_reason' => $p->allocationReason,
                // The date promised for this parcel (null when the promise is switched off): see docs/guides/DELIVERY_PROMISE_ASSEMBLY_AND_SHARING.md.
                'promise' => $p->promise,
            ], $this->plans),
            'lines' => $this->lines()->map($line)->all(),
            'totals' => [
                'subtotal' => $this->subtotal()->jsonSerialize(),
                'discount' => $this->discountTotal()->jsonSerialize(),
                // The part of `discount` that comes from deal prices ("0.00" when none).
                'deal_savings' => $this->dealSavingsTotal()->jsonSerialize(),
                'delivery_fee' => $this->deliveryFeeTotal()->jsonSerialize(),
                'extra_delivery_fee' => $this->extraDeliveryFeeTotal()->jsonSerialize(),
                // Assembly and installation chosen on delivery lines ("0.00" when none); already inside `grand_total`.
                'assembly_fee' => $this->assemblyTotal()->jsonSerialize(),
                'service_fee' => $this->serviceFee->jsonSerialize(),
                // Kept so older app builds that read it keep working: cash on delivery never costs extra, so it is always "0.00".
                'cod_fee' => Money::zero()->jsonSerialize(),
                'tax' => $this->taxTotal()->jsonSerialize(),
                'loyalty_discount' => $this->loyaltyDiscount->jsonSerialize(),
                'grand_total' => $this->grandTotal()->jsonSerialize(),
            ],
            'delivery_extras' => array_map(fn (DeliveryExtra $e) => $e->toArray(), $this->deliveryExtras()),
            'discounts' => array_map(fn (AppliedDiscount $d) => $d->toArray(), $this->discounts),
            'promise' => $this->promise,
            'payment_methods' => $this->paymentMethods,
            'loyalty' => ['balance' => $this->loyaltyBalance, 'max_redeemable_points' => $this->loyaltyMax, 'points_applied' => $this->loyaltyPoints, 'points_to_earn' => $this->pointsToEarn],
            'address' => $this->address ? ['id' => $this->address->id, 'label' => $this->address->label, 'summary' => $this->address->oneLine()] : null,
            'delivery_zone' => $this->zone ? ['id' => $this->zone->id, 'name' => $this->zone->localized('name')] : null,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'hints' => $this->hints,
            'can_place_order' => $this->canPlace(),
            'expires_at' => BusinessTime::iso($this->expiresAt),
        ];
    }

    private function flat(array $context): array
    {
        return array_map(fn ($v) => is_array($v) ? ($v['formatted'] ?? json_encode($v)) : $v, $context);
    }
}
