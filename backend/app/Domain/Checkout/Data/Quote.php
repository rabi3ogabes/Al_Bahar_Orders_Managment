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

    public Money $codFee;

    public string $taxRate = '0';

    public array $paymentMethods = [];

    public array $errors = [];

    public array $warnings = [];

    public array $hints = [];

    public ?string $hash = null;

    public $expiresAt = null;

    public function __construct()
    {
        $this->loyaltyDiscount = Money::zero();
        $this->serviceFee = Money::zero();
        $this->codFee = Money::zero();
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

    public function deliveryFeeTotal(): Money
    {
        return array_reduce($this->plans, fn (Money $c, FulfillmentPlan $p) => $c->plus($p->deliveryFee), Money::zero());
    }

    public function deliveryDiscountTotal(): Money
    {
        $free = collect($this->discounts)->first(fn (AppliedDiscount $d) => $d->freeDelivery);

        return $free ? $this->deliveryFeeTotal() : Money::zero();
    }

    public function taxTotal(): Money
    {
        return $this->lines()->reduce(fn (Money $c, QuoteLine $l) => $c->plus($l->tax), Money::zero());
    }

    public function discountTotal(): Money
    {
        return $this->itemDiscountTotal()->plus($this->deliveryDiscountTotal());
    }

    public function grandTotalBeforeCod(): Money
    {
        $total = $this->subtotal()->minus($this->itemDiscountTotal())->plus($this->deliveryFeeTotal())->minus($this->deliveryDiscountTotal())
            ->plus($this->serviceFee)->plus($this->taxTotal())->minus($this->loyaltyDiscount);

        return $total->isNegative() ? Money::zero() : $total;
    }

    public function grandTotal(): Money
    {
        return $this->grandTotalBeforeCod()->plus($this->codFee);
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
                'l' => array_map(fn (QuoteLine $l) => [$l->variant->id, $l->quantity, $l->price->final()->minor, $l->discount->minor, $l->tax->minor], $p->lines),
            ], $this->plans),
            'disc' => array_map(fn (AppliedDiscount $d) => [$d->promotion->id, $d->amount->minor, $d->deliveryDiscount->minor], $this->discounts),
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
                'eta_minutes' => $p->zoneConfig?->estimated_minutes,
                'slot' => $p->slotId ? ['id' => $p->slotId, 'date' => $p->slotDate] + ($p->slotInfo ?? []) : null,
                'slot_required' => $p->slotRequired,
                'allocation_reason' => $p->allocationReason,
            ], $this->plans),
            'lines' => $this->lines()->map($line)->all(),
            'totals' => [
                'subtotal' => $this->subtotal()->jsonSerialize(),
                'discount' => $this->discountTotal()->jsonSerialize(),
                'delivery_fee' => $this->deliveryFeeTotal()->jsonSerialize(),
                'service_fee' => $this->serviceFee->jsonSerialize(),
                'cod_fee' => $this->codFee->jsonSerialize(),
                'tax' => $this->taxTotal()->jsonSerialize(),
                'loyalty_discount' => $this->loyaltyDiscount->jsonSerialize(),
                'grand_total' => $this->grandTotal()->jsonSerialize(),
            ],
            'discounts' => array_map(fn (AppliedDiscount $d) => $d->toArray(), $this->discounts),
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
