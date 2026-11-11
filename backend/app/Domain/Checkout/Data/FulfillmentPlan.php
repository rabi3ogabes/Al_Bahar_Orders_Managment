<?php

namespace App\Domain\Checkout\Data;

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchDeliveryZone;
use App\Support\Money;

final class FulfillmentPlan
{
    /** Set by the quote: whether the customer must pick a time slot, and the chosen slot's details. */
    public bool $slotRequired = false;

    /** @var array{starts_at: string, ends_at: string}|null */
    public ?array $slotInfo = null;

    /** @param list<QuoteLine> $lines */
    public function __construct(
        public readonly Branch $branch,
        public readonly string $type,                 // delivery | pickup
        public array $lines,
        public readonly ?BranchDeliveryZone $zoneConfig = null,
        public ?Money $deliveryFee = null,
        public readonly ?string $slotId = null,
        public readonly ?string $slotDate = null,
        public readonly bool $express = false,
        public readonly string $allocationReason = 'selected_branch',
    ) {
        $this->deliveryFee ??= Money::zero();
    }

    public function subtotal(): Money
    {
        return array_reduce($this->lines, fn (Money $c, QuoteLine $l) => $c->plus($l->subtotal()), Money::zero());
    }

    public function discountedSubtotal(): Money
    {
        return array_reduce($this->lines, fn (Money $c, QuoteLine $l) => $c->plus($l->subtotal()->minus($l->discount)), Money::zero());
    }
}
