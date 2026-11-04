<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\GoodsReceipt;
use App\Domain\Inventory\Models\PurchaseOrder;
use App\Support\DocumentNumber;
use App\Support\DomainException;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Purchase orders: draft → pending_approval → approved → partially_received → received → closed (or cancelled/rejected).
 * Goods receipts add stock through PURCHASE_RECEIPT movements with unit cost (weighted average cost maintained).
 */
class PurchasingService
{
    public function __construct(private readonly InventoryLedger $ledger, private readonly AccessControl $access, private readonly AuditLogger $audit) {}

    /** @param list<array{variant_id: string, quantity: int, unit_cost: string}> $lines */
    public function create(User $actor, string $supplierId, string $branchId, array $lines, array $meta = []): PurchaseOrder
    {
        $this->authorize($actor, 'purchasing.create', $branchId);
        if (! $lines) {
            throw DomainException::make('validation_failed');
        }

        return DB::transaction(function () use ($actor, $supplierId, $branchId, $lines, $meta) {
            $po = PurchaseOrder::create(['number' => DocumentNumber::make('PO'), 'supplier_id' => $supplierId, 'branch_id' => $branchId,
                'status' => 'draft', 'currency' => 'QAR', 'created_by' => $actor->id,
                'expected_at' => $meta['expected_at'] ?? null, 'notes' => $meta['notes'] ?? null, 'supplier_reference' => $meta['supplier_reference'] ?? null]);
            $subtotal = Money::zero();
            foreach ($lines as $l) {
                if ((int) $l['quantity'] <= 0 || Money::of((string) $l['unit_cost'])->isNegative()) {
                    throw DomainException::make('quantity_invalid');
                }
                $lineTotal = $this->lineTotal((string) $l['unit_cost'], (int) $l['quantity']);
                $po->items()->create(['product_variant_id' => $l['variant_id'], 'quantity_ordered' => (int) $l['quantity'],
                    'unit_cost' => $l['unit_cost'], 'line_total' => $lineTotal->toDecimal()]);
                $subtotal = $subtotal->plus($lineTotal);
            }
            $po->update(['subtotal' => $subtotal->toDecimal()]);
            $this->audit->log('purchasing.po_created', $po, [], ['lines' => count($lines), 'subtotal' => $subtotal->toDecimal()], actor: $actor);

            return $po->load('items');
        });
    }

    public function submit(User $actor, PurchaseOrder $po): PurchaseOrder
    {
        $this->authorize($actor, 'purchasing.create', $po->branch_id);

        return $this->transition($po, ['draft'], 'pending_approval', $actor, ['submitted_at' => now()]);
    }

    public function approve(User $actor, PurchaseOrder $po): PurchaseOrder
    {
        $this->authorize($actor, 'purchasing.approve', $po->branch_id);
        if ($po->created_by === $actor->id && ! $actor->isSuperAdmin()) {
            throw DomainException::make('approval_segregation', 403);
        }

        return $this->transition($po, ['pending_approval'], 'approved', $actor, ['approved_by' => $actor->id, 'approved_at' => now()]);
    }

    public function reject(User $actor, PurchaseOrder $po, ?string $reason = null): PurchaseOrder
    {
        $this->authorize($actor, 'purchasing.approve', $po->branch_id);

        return $this->transition($po, ['pending_approval'], 'rejected', $actor, reason: $reason);
    }

    /**
     * Record a (possibly partial) goods receipt.
     *
     * @param  array<string, array{received: int, rejected?: int, unit_cost?: string, rejection_reason?: string}>  $lines  PO item id → quantities
     */
    public function receive(User $actor, PurchaseOrder $po, array $lines, ?string $supplierInvoice = null, ?string $notes = null): GoodsReceipt
    {
        $this->authorize($actor, 'inventory.create', $po->branch_id);

        return DB::transaction(function () use ($actor, $po, $lines, $supplierInvoice, $notes) {
            $po = PurchaseOrder::whereKey($po->id)->lockForUpdate()->first();
            if (! in_array($po->status, ['approved', 'partially_received'], true)) {
                throw DomainException::make('invalid_state_transition', 409, ['from' => $po->status]);
            }
            $receipt = GoodsReceipt::create(['number' => DocumentNumber::make('GRN'), 'purchase_order_id' => $po->id, 'branch_id' => $po->branch_id,
                'supplier_invoice_number' => $supplierInvoice, 'notes' => $notes, 'received_by' => $actor->id, 'received_at' => now()]);

            foreach ($po->items()->lockForUpdate()->get() as $item) {
                $in = $lines[$item->id] ?? null;
                if (! $in) {
                    continue;
                }
                $received = max(0, (int) $in['received']);
                $rejected = max(0, (int) ($in['rejected'] ?? 0));
                if ($received + $rejected === 0) {
                    continue;
                }
                if ($item->quantity_received + $received > $item->quantity_ordered) {
                    throw DomainException::make('purchase_over_receipt', 422, ['item_id' => $item->id, 'outstanding' => $item->quantity_ordered - $item->quantity_received]);
                }
                $unitCost = (string) ($in['unit_cost'] ?? $item->unit_cost);
                $receipt->items()->create(['purchase_order_item_id' => $item->id, 'product_variant_id' => $item->product_variant_id,
                    'quantity_received' => $received, 'quantity_rejected' => $rejected, 'unit_cost' => $unitCost,
                    'rejection_reason' => $in['rejection_reason'] ?? null]);
                if ($received > 0) {
                    $this->ledger->apply($po->branch_id, $item->product_variant_id, MovementType::PurchaseReceipt, $received, 0,
                        'goods_receipt', $receipt->id, 'PO '.$po->number, $actor->id, $unitCost);
                    $item->increment('quantity_received', $received);
                }
            }
            $po->load('items');
            $complete = $po->items->every(fn ($i) => $i->quantity_received >= $i->quantity_ordered);
            $this->transition($po, ['approved', 'partially_received'], $complete ? 'received' : 'partially_received', $actor);

            return $receipt->load('items');
        });
    }

    public function close(User $actor, PurchaseOrder $po): PurchaseOrder
    {
        $this->authorize($actor, 'purchasing.approve', $po->branch_id);

        return $this->transition($po, ['received', 'partially_received'], 'closed', $actor, ['closed_at' => now()]);
    }

    public function cancel(User $actor, PurchaseOrder $po, ?string $reason = null): PurchaseOrder
    {
        $this->authorize($actor, 'purchasing.approve', $po->branch_id);

        return $this->transition($po, ['draft', 'pending_approval', 'approved'], 'cancelled', $actor, reason: $reason);
    }

    private function lineTotal(string $unitCost, int $qty): Money
    {
        // unit_cost has up to 4 decimals; compute at 4dp then round half-up to 2dp.
        [$whole, $frac] = array_pad(explode('.', $unitCost), 2, '0');
        $micro = (int) $whole * 10000 + (int) str_pad(substr($frac, 0, 4), 4, '0');
        $totalMicro = $micro * $qty;

        return Money::ofMinor(intdiv($totalMicro + 50, 100));
    }

    private function transition(PurchaseOrder $po, array $from, string $to, User $actor, array $extra = [], ?string $reason = null): PurchaseOrder
    {
        if (! in_array($po->status, $from, true)) {
            throw DomainException::make('invalid_state_transition', 409, ['from' => $po->status]);
        }
        $old = $po->status;
        $po->update(['status' => $to] + $extra);
        $this->audit->log('purchasing.po_'.$to, $po, ['status' => $old], array_filter(['status' => $to, 'reason' => $reason]), actor: $actor);

        return $po;
    }

    private function authorize(User $actor, string $permission, string $branchId): void
    {
        if (! $this->access->allows($actor, $permission, $branchId)) {
            throw DomainException::make('branch_forbidden', 403);
        }
    }
}
