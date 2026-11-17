<?php

namespace App\Domain\Deals\Data;

use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\DealProduct;
use App\Support\BusinessTime;
use App\Support\Money;

/**
 * What a deal did to ONE quote line: how many of its units get the deal price, how much each saves and, when fewer than all of them do, why.
 * `units` may be 0 (the deal exists but this customer gets none of it: limit reached or sold out): the line still says so in plain words.
 */
final class LineDeal
{
    public const LIMIT_PER_ORDER = 'limit_per_order';

    public const LIMIT_PER_CUSTOMER = 'limit_per_customer';

    public const SOLD_OUT = 'sold_out';

    public const ENDED = 'ended';

    public function __construct(
        public readonly Deal $deal,
        public readonly DealProduct $row,
        public readonly int $units,
        public readonly int $quantity,
        public readonly Money $unitSavings,
        public readonly ?string $note,
    ) {}

    public function unitsAtRegularPrice(): int
    {
        return $this->quantity - $this->units;
    }

    public function savings(): Money
    {
        return $this->unitSavings->times($this->units);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'deal_id' => $this->deal->id, 'name' => $this->deal->localized('name'),
            'units_at_deal_price' => $this->units, 'units_at_regular_price' => $this->unitsAtRegularPrice(),
            'savings' => $this->savings()->jsonSerialize(), 'ends_at' => BusinessTime::iso($this->deal->ends_at), 'note' => $this->note,
        ];
    }
}
