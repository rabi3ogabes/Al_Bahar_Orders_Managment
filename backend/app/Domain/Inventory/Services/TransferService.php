<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\StockTransferItem;
use App\Support\DocumentNumber;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Inter-branch transfers with explicit in-transit tracking:
 *   draft → pending_approval → approved (source stock RESERVED) → in_transit (source on_hand reduced by dispatched qty;
 *   undispatched reservation released) → partially_received / received (destination on_hand increased by received qty)
 *   → completed (missing = dispatched − received − damaged is recorded as a discrepancy).
 * In-transit quantity per line = dispatched − received − damaged.
 */
class TransferService
{
    public function __construct(
        private readonly InventoryLedger $ledger,
        private readonly ReservationService $reservations,
        private readonly AccessControl $access,
        private readonly AuditLogger $audit,
    ) {}

    /** @param list<array{variant_id: string, quantity: int}> $items */
    public function create(User $actor, string $sourceId, string $destinationId, array $items, ?string $notes = null): StockTransfer
    {
        $this->authorize($actor, 'transfers.create', $sourceId);
        if ($sourceId === $destinationId) {
            throw DomainException::make('transfer_same_branch');
        }

        return DB::transaction(function () use ($actor, $sourceId, $destinationId, $items, $notes) {
            $transfer = StockTransfer::create([
                'number' => DocumentNumber::make('TR'), 'source_branch_id' => $sourceId, 'destination_branch_id' => $destinationId,
                'status' => 'draft', 'notes' => $notes, 'requested_by' => $actor->id,
            ]);
            foreach ($this->mergeLines($items) as $variantId => $qty) {
                $transfer->items()->create(['product_variant_id' => $variantId, 'quantity_requested' => $qty]);
            }
            $this->audit->log('transfer.created', $transfer, [], ['items' => $items], branchId: $sourceId, actor: $actor);

            return $transfer->load('items');
        });
    }

    public function submit(User $actor, StockTransfer $transfer): StockTransfer
    {
        $this->authorize($actor, 'transfers.create', $transfer->source_branch_id);

        return $this->transition($transfer, ['draft'], 'pending_approval', $actor, ['submitted_at' => now()]);
    }

    /**
     * Approve (optionally reducing quantities) and reserve stock at the source.
     *
     * @param  array<string, int>  $approvedQuantities  item id → qty (defaults to requested)
     */
    public function approve(User $actor, StockTransfer $transfer, array $approvedQuantities = []): StockTransfer
    {
        $this->authorize($actor, 'transfers.approve', $transfer->source_branch_id);
        if ($transfer->requested_by === $actor->id && ! $actor->isSuperAdmin()) {
            throw DomainException::make('approval_segregation', 403); // maker ≠ checker
        }

        return DB::transaction(function () use ($actor, $transfer, $approvedQuantities) {
            $transfer = StockTransfer::whereKey($transfer->id)->lockForUpdate()->first();
            $this->assertStatus($transfer, ['pending_approval']);
            $lines = [];
            foreach ($transfer->items as $item) {
                $qty = min($item->quantity_requested, max(0, (int) ($approvedQuantities[$item->id] ?? $item->quantity_requested)));
                $item->update(['quantity_approved' => $qty]);
                if ($qty > 0) {
                    $lines[] = ['branch_id' => $transfer->source_branch_id, 'variant_id' => $item->product_variant_id, 'quantity' => $qty,
                        'reference_type' => 'transfer_item', 'reference_id' => $item->id];
                }
            }
            if (! $lines) {
                throw DomainException::make('transfer_empty');
            }
            $this->reservations->reserve($lines, null, $actor->id);

            return $this->transition($transfer, ['pending_approval'], 'approved', $actor, ['approved_by' => $actor->id, 'approved_at' => now()]);
        });
    }

    public function reject(User $actor, StockTransfer $transfer, string $reason): StockTransfer
    {
        $this->authorize($actor, 'transfers.approve', $transfer->source_branch_id);

        return $this->transition($transfer, ['pending_approval'], 'rejected', $actor, ['cancellation_reason' => $reason, 'cancelled_at' => now()]);
    }

    /** @param array<string, int> $dispatchQuantities item id → qty (defaults to approved). Supports partial dispatch. */
    public function dispatch(User $actor, StockTransfer $transfer, array $dispatchQuantities = []): StockTransfer
    {
        $this->authorize($actor, 'transfers.edit', $transfer->source_branch_id);

        return DB::transaction(function () use ($actor, $transfer, $dispatchQuantities) {
            $transfer = StockTransfer::whereKey($transfer->id)->lockForUpdate()->first();
            $this->assertStatus($transfer, ['approved']);
            foreach ($transfer->items as $item) {
                $qty = min($item->quantity_approved, max(0, (int) ($dispatchQuantities[$item->id] ?? $item->quantity_approved)));
                $reservation = $this->reservations->forReference('transfer_item', $item->id);
                if ($reservation) {
                    // Consumes the dispatched quantity (TRANSFER_OUT) and releases any undispatched remainder.
                    $this->reservations->consume($reservation, $qty, MovementType::TransferOut, $actor->id, 'stock_transfer', $transfer->id);
                }
                $item->update(['quantity_dispatched' => $qty]);
            }

            return $this->transition($transfer, ['approved'], 'in_transit', $actor, ['dispatched_by' => $actor->id, 'dispatched_at' => now()]);
        });
    }

    /**
     * Receive at destination. May be called multiple times (partial receipts).
     *
     * @param  array<string, array{received: int, damaged?: int, note?: string}>  $lines  item id → quantities in THIS receipt
     */
    public function receive(User $actor, StockTransfer $transfer, array $lines): StockTransfer
    {
        $this->authorize($actor, 'transfers.edit', $transfer->destination_branch_id);

        return DB::transaction(function () use ($actor, $transfer, $lines) {
            $transfer = StockTransfer::whereKey($transfer->id)->lockForUpdate()->first();
            $this->assertStatus($transfer, ['in_transit', 'partially_received']);
            foreach ($transfer->items()->lockForUpdate()->get() as $item) {
                $in = $lines[$item->id] ?? null;
                if (! $in) {
                    continue;
                }
                $received = max(0, (int) $in['received']);
                $damaged = max(0, (int) ($in['damaged'] ?? 0));
                $outstanding = $item->quantity_dispatched - $item->quantity_received - $item->quantity_damaged;
                if ($received + $damaged > $outstanding) {
                    throw DomainException::make('transfer_over_receipt', 422, ['item_id' => $item->id, 'outstanding' => $outstanding]);
                }
                if ($received > 0) {
                    $this->ledger->apply($transfer->destination_branch_id, $item->product_variant_id, MovementType::TransferIn, $received, 0,
                        'stock_transfer', $transfer->id, null, $actor->id);
                }
                $item->update([
                    'quantity_received' => $item->quantity_received + $received,
                    'quantity_damaged' => $item->quantity_damaged + $damaged,
                    'discrepancy_note' => trim(($item->discrepancy_note ? $item->discrepancy_note."\n" : '').($in['note'] ?? '')) ?: null,
                ]);
            }
            $transfer->load('items');
            $fully = $transfer->items->every(fn (StockTransferItem $i) => $i->quantity_received + $i->quantity_damaged >= $i->quantity_dispatched);

            return $this->transition($transfer, ['in_transit', 'partially_received'], $fully ? 'received' : 'partially_received', $actor,
                ['received_by' => $actor->id, 'received_at' => now()]);
        });
    }

    /** Close the transfer; anything still in transit is recorded as missing. */
    public function complete(User $actor, StockTransfer $transfer): StockTransfer
    {
        $this->authorize($actor, 'transfers.approve', $transfer->destination_branch_id);

        return DB::transaction(function () use ($actor, $transfer) {
            $transfer = StockTransfer::whereKey($transfer->id)->lockForUpdate()->first();
            $this->assertStatus($transfer, ['received', 'partially_received']);
            $missing = [];
            foreach ($transfer->items as $item) {
                $m = $item->quantity_dispatched - $item->quantity_received - $item->quantity_damaged;
                if ($m > 0) {
                    $missing[$item->product_variant_id] = $m;
                }
            }
            if ($missing || $transfer->items->sum('quantity_damaged') > 0) {
                $this->audit->log('transfer.discrepancy', $transfer, [], ['missing' => $missing,
                    'damaged' => $transfer->items->where('quantity_damaged', '>', 0)->pluck('quantity_damaged', 'product_variant_id')], branchId: $transfer->destination_branch_id, actor: $actor);
            }

            return $this->transition($transfer, ['received', 'partially_received'], 'completed', $actor, ['completed_at' => now()]);
        });
    }

    public function cancel(User $actor, StockTransfer $transfer, string $reason): StockTransfer
    {
        $this->authorize($actor, 'transfers.approve', $transfer->source_branch_id);

        return DB::transaction(function () use ($actor, $transfer, $reason) {
            $transfer = StockTransfer::whereKey($transfer->id)->lockForUpdate()->first();
            $this->assertStatus($transfer, ['draft', 'pending_approval', 'approved']);
            foreach ($transfer->items as $item) {
                if ($r = $this->reservations->forReference('transfer_item', $item->id)) {
                    $this->reservations->release($r, 'transfer_cancelled', actorId: $actor->id);
                }
            }

            return $this->transition($transfer, ['draft', 'pending_approval', 'approved'], 'cancelled', $actor, ['cancellation_reason' => $reason, 'cancelled_at' => now()]);
        });
    }

    public function inTransit(StockTransferItem $item): int
    {
        return max(0, $item->quantity_dispatched - $item->quantity_received - $item->quantity_damaged);
    }

    private function transition(StockTransfer $transfer, array $from, string $to, User $actor, array $extra = []): StockTransfer
    {
        $this->assertStatus($transfer, $from);
        $old = $transfer->status;
        $transfer->update(['status' => $to] + $extra);
        $this->audit->log('transfer.'.$to, $transfer, ['status' => $old], ['status' => $to], branchId: $transfer->source_branch_id, actor: $actor);

        return $transfer->load('items');
    }

    private function assertStatus(StockTransfer $transfer, array $allowed): void
    {
        if (! in_array($transfer->status, $allowed, true)) {
            throw DomainException::make('invalid_state_transition', 409, ['from' => $transfer->status]);
        }
    }

    private function authorize(User $actor, string $permission, string $branchId): void
    {
        if (! $this->access->allows($actor, $permission, $branchId)) {
            throw DomainException::make('branch_forbidden', 403);
        }
    }

    private function mergeLines(array $items): array
    {
        $out = [];
        foreach ($items as $i) {
            if ((int) $i['quantity'] <= 0) {
                throw DomainException::make('quantity_invalid');
            }
            $out[$i['variant_id']] = ($out[$i['variant_id']] ?? 0) + (int) $i['quantity'];
        }

        return $out;
    }
}
