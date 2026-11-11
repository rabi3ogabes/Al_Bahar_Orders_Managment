<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Events\BackInStock;
use App\Domain\Inventory\Models\BranchInventory;
use App\Domain\Inventory\Models\StockMovement;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * THE ONLY code path that changes branch stock.
 *
 * Every call: (1) runs in a transaction, (2) takes a row lock on the (branch, variant) balance row,
 * (3) validates invariants (on_hand >= 0, 0 <= reserved <= on_hand), (4) updates the projection and
 * (5) writes an immutable stock_movements row with the resulting balances, reference and actor.
 * The database CHECK constraints are a second line of defence.
 */
class InventoryLedger
{
    /**
     * @param  int  $onHandDelta  signed change to on_hand
     * @param  int  $reservedDelta  signed change to reserved
     */
    public function apply(
        string $branchId,
        string $variantId,
        MovementType $type,
        int $onHandDelta,
        int $reservedDelta = 0,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $reason = null,
        ?string $actorId = null,
        ?string $unitCost = null,
    ): StockMovement {
        if ($onHandDelta === 0 && $reservedDelta === 0) {
            throw new \InvalidArgumentException('A stock movement must change on_hand or reserved.');
        }

        return DB::transaction(function () use ($branchId, $variantId, $type, $onHandDelta, $reservedDelta, $referenceType, $referenceId, $reason, $actorId, $unitCost) {
            $row = $this->lock($branchId, $variantId);
            $oldAvailable = $row->available();
            $newOnHand = $row->on_hand + $onHandDelta;
            $newReserved = $row->reserved + $reservedDelta;
            if ($newOnHand < 0 || $newReserved < 0 || $newReserved > $newOnHand) {
                throw DomainException::make('stock_would_go_negative', 422, [
                    'branch_id' => $branchId, 'variant_id' => $variantId, 'on_hand' => $row->on_hand, 'reserved' => $row->reserved,
                    'available' => $row->available(),
                ]);
            }

            // Weighted average cost on inbound stock with a known unit cost.
            if ($unitCost !== null && $onHandDelta > 0) {
                $oldQty = max(0, $row->on_hand);
                $oldAvg = (float) ($row->average_cost ?? $unitCost);
                $row->average_cost = number_format((($oldAvg * $oldQty) + ((float) $unitCost * $onHandDelta)) / ($oldQty + $onHandDelta), 4, '.', '');
            }

            BranchInventory::$ledgerWriting = true;
            try {
                $row->forceFill(['on_hand' => $newOnHand, 'reserved' => $newReserved, 'last_movement_at' => now()])->save();
            } finally {
                BranchInventory::$ledgerWriting = false;
            }

            $movement = StockMovement::create([
                'branch_id' => $branchId,
                'product_variant_id' => $variantId,
                'type' => $type->value,
                'quantity' => $onHandDelta,
                'reserved_delta' => $reservedDelta,
                'on_hand_after' => $newOnHand,
                'reserved_after' => $newReserved,
                'unit_cost' => $unitCost,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'actor_id' => $actorId ?? auth()->id(),
                'reason' => $reason ? Str::limit($reason, 495) : null,
            ]);

            if ($oldAvailable <= 0 && $row->available() > 0) {
                event(new BackInStock($branchId, $variantId));
            }

            return $movement;
        });
    }

    /** Lock (creating if absent) the balance row for (branch, variant). Must be inside a transaction. */
    public function lock(string $branchId, string $variantId): BranchInventory
    {
        DB::table('branch_inventory')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'branch_id' => $branchId, 'product_variant_id' => $variantId,
            'on_hand' => 0, 'reserved' => 0, 'minimum_stock' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return BranchInventory::where('branch_id', $branchId)->where('product_variant_id', $variantId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Lock many rows in a deterministic order (branch, variant) to prevent deadlocks between concurrent checkouts.
     *
     * @param  list<array{0: string, 1: string}>  $pairs  [branchId, variantId]
     * @return array<string, BranchInventory> keyed "branchId|variantId"
     */
    public function lockMany(array $pairs): array
    {
        $pairs = collect($pairs)->unique(fn ($p) => $p[0].'|'.$p[1])->sortBy(fn ($p) => $p[0].'|'.$p[1])->values();
        $rows = [];
        foreach ($pairs as [$branchId, $variantId]) {
            $rows[$branchId.'|'.$variantId] = $this->lock($branchId, $variantId);
        }

        return $rows;
    }

    public function available(string $branchId, string $variantId): int
    {
        $row = BranchInventory::where('branch_id', $branchId)->where('product_variant_id', $variantId)->first();

        return $row ? $row->available() : 0;
    }
}
