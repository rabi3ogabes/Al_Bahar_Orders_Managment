<?php

namespace App\Domain\Inventory\Support;

use App\Domain\Inventory\Services\StockPlanning;
use Carbon\CarbonImmutable;

/**
 * One line of the stock planning page: a variant at a branch, with everything the manager needs to decide about it.
 * Built from a row of {@see StockPlanning}'s query, which works every number out once (the page, the counts and the CSV
 * can therefore never disagree).
 */
final class PlanRow
{
    public function __construct(
        public readonly string $id,
        public readonly string $branchId,
        public readonly string $variantId,
        public readonly int $onHand,
        public readonly int $reserved,
        public readonly int $available,
        public readonly int $minimumStock,
        public readonly ?CarbonImmutable $outSince,
        public readonly int $unitsSold,
        public readonly int $salesLines,
        public readonly float $dailyDemand,
        public readonly bool $oneOffOrder,
        public readonly int $historyDays,
        public readonly int $sellingDays,
        public readonly bool $littleHistory,
        public readonly ?float $coverDays,
        public readonly int $leadDays,
        public readonly int $safetyDays,
        public readonly int $reorderPoint,
        public readonly bool $reorderPointFixed,
        public readonly int $incoming,
        public readonly ?string $incomingDate,
        public readonly int $waiting,
        public readonly int $suggested,
        public readonly bool $suggestedFixed,
        public readonly string $lane,
        public readonly bool $isOut,
        public readonly bool $isLow,
        public readonly bool $isReorder,
        public readonly bool $isSlow,
        public readonly bool $leadFixed,
        public readonly bool $safetyFixed,
    ) {}

    public static function fromRow(object $r): self
    {
        return new self(
            id: (string) $r->id, branchId: (string) $r->branch_id, variantId: (string) $r->product_variant_id,
            onHand: (int) $r->on_hand, reserved: (int) $r->reserved, available: (int) $r->available, minimumStock: (int) $r->minimum_stock,
            outSince: $r->out_since !== null ? CarbonImmutable::parse((string) $r->out_since) : null,
            unitsSold: (int) $r->units, salesLines: (int) $r->sale_lines, dailyDemand: round((float) $r->daily, 3), oneOffOrder: (bool) $r->spiky,
            historyDays: (int) $r->history_days, sellingDays: (int) $r->selling_days, littleHistory: (bool) $r->little_history,
            coverDays: $r->cover_days !== null ? round((float) $r->cover_days, 1) : null,
            leadDays: (int) $r->lead, safetyDays: (int) $r->safety, reorderPoint: (int) $r->rop, reorderPointFixed: (bool) $r->rop_is_fixed,
            incoming: (int) $r->incoming, incomingDate: $r->next_at !== null ? (string) $r->next_at : null,
            waiting: (int) $r->waiting, suggested: (int) $r->suggest, suggestedFixed: (bool) $r->qty_is_fixed, lane: (string) $r->lane,
            isOut: (bool) $r->is_out, isLow: (bool) $r->is_low, isReorder: (bool) $r->is_reorder, isSlow: (bool) $r->is_slow,
            leadFixed: (bool) $r->lead_is_fixed, safetyFixed: (bool) $r->safety_is_fixed,
        );
    }

    /** Whole days the item has been out of stock (0 when it is not, or since today). */
    public function daysOut(?CarbonImmutable $now = null): ?int
    {
        return $this->outSince === null ? null : (int) max(0, floor($this->outSince->diffInHours($now ?? CarbonImmutable::now(), false) / 24));
    }
}
