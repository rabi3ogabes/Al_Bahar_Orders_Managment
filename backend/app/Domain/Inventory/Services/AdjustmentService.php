<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\InventoryAdjustment;
use App\Support\DocumentNumber;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Manual stock changes with reason codes: opening balance, adjustment in/out, damaged, lost, customer return.
 * Staff with inventory.approve post immediately; others create a pending adjustment that a different approver posts.
 */
class AdjustmentService
{
    private const TYPES = ['adjustment', 'damaged', 'lost', 'opening_balance', 'customer_return'];

    public function __construct(private readonly InventoryLedger $ledger, private readonly AccessControl $access, private readonly AuditLogger $audit) {}

    /** @param list<array{variant_id: string, quantity: int, unit_cost?: ?string, note?: ?string}> $items quantity is signed */
    public function create(User $actor, string $branchId, string $type, string $reason, array $items): InventoryAdjustment
    {
        if (! $this->access->allows($actor, 'inventory.create', $branchId)) {
            throw DomainException::make('branch_forbidden', 403);
        }
        if (! in_array($type, self::TYPES, true) || trim($reason) === '' || ! $items) {
            throw DomainException::make('validation_failed');
        }
        foreach ($items as $i) {
            $q = (int) $i['quantity'];
            if ($q === 0 || (in_array($type, ['damaged', 'lost'], true) && $q > 0) || (in_array($type, ['opening_balance', 'customer_return'], true) && $q < 0)) {
                throw DomainException::make('quantity_invalid');
            }
        }

        $adjustment = DB::transaction(function () use ($actor, $branchId, $type, $reason, $items) {
            $adj = InventoryAdjustment::create(['number' => DocumentNumber::make('ADJ'), 'branch_id' => $branchId, 'type' => $type,
                'status' => 'pending_approval', 'reason' => $reason, 'created_by' => $actor->id]);
            foreach ($items as $i) {
                $adj->items()->create(['product_variant_id' => $i['variant_id'], 'quantity' => (int) $i['quantity'],
                    'unit_cost' => $i['unit_cost'] ?? null, 'note' => $i['note'] ?? null]);
            }
            $this->audit->log('inventory.adjustment_created', $adj, [], ['type' => $type, 'items' => $items, 'reason' => $reason], actor: $actor);

            return $adj;
        });

        // Self-approval only for opening balances by an approver; everything else needs a second person if available.
        if ($type === 'opening_balance' && $this->access->allows($actor, 'inventory.approve', $branchId)) {
            return $this->post($actor, $adjustment, allowSelf: true);
        }

        return $adjustment->load('items');
    }

    public function post(User $actor, InventoryAdjustment $adjustment, bool $allowSelf = false): InventoryAdjustment
    {
        if (! $this->access->allows($actor, 'inventory.approve', $adjustment->branch_id)) {
            throw DomainException::make('branch_forbidden', 403);
        }
        if (! $allowSelf && $adjustment->created_by === $actor->id && ! $actor->isSuperAdmin()) {
            throw DomainException::make('approval_segregation', 403);
        }

        return DB::transaction(function () use ($actor, $adjustment) {
            $adj = InventoryAdjustment::whereKey($adjustment->id)->lockForUpdate()->first();
            if ($adj->status !== 'pending_approval') {
                throw DomainException::make('invalid_state_transition', 409, ['from' => $adj->status]);
            }
            foreach ($adj->items as $item) {
                $type = match ($adj->type) {
                    'damaged' => MovementType::Damaged,
                    'lost' => MovementType::Lost,
                    'opening_balance' => MovementType::OpeningBalance,
                    'customer_return' => MovementType::CustomerReturn,
                    default => $item->quantity > 0 ? MovementType::AdjustmentIn : MovementType::AdjustmentOut,
                };
                $this->ledger->apply($adj->branch_id, $item->product_variant_id, $type, $item->quantity, 0,
                    'inventory_adjustment', $adj->id, $adj->reason.($item->note ? ' — '.$item->note : ''), $actor->id, $item->unit_cost);
            }
            $adj->update(['status' => 'posted', 'approved_by' => $actor->id, 'posted_at' => now()]);
            $this->audit->log('inventory.adjustment_posted', $adj, ['status' => 'pending_approval'], ['status' => 'posted'], actor: $actor);

            return $adj->load('items');
        });
    }

    public function reject(User $actor, InventoryAdjustment $adjustment, string $reason): InventoryAdjustment
    {
        if (! $this->access->allows($actor, 'inventory.approve', $adjustment->branch_id) || $adjustment->status !== 'pending_approval') {
            throw DomainException::make('branch_forbidden', 403);
        }
        $adjustment->update(['status' => 'rejected', 'approved_by' => $actor->id]);
        $this->audit->log('inventory.adjustment_rejected', $adjustment, [], ['reason' => $reason], actor: $actor);

        return $adjustment;
    }
}
