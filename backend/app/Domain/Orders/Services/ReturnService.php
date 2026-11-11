<?php

namespace App\Domain\Orders\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\ReturnRequest;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\Services\RefundService;
use App\Support\DomainException;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Customer return requests: requested → approved | rejected; approved → received (goods back at the branch,
 * optionally restocked as customer_return) → refunded (refund raised via RefundService) → closed.
 * Decisions need orders.approve at the return's branch; receiving needs orders.edit.
 */
class ReturnService
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly InventoryLedger $ledger,
        private readonly OrderService $orders,
        private readonly AuditLogger $audit,
    ) {}

    public function decide(ReturnRequest $rr, User $staff, bool $approve, ?string $note = null): ReturnRequest
    {
        $this->authorize($staff, 'orders.approve', $rr);

        return DB::transaction(function () use ($rr, $staff, $approve, $note) {
            $r = ReturnRequest::whereKey($rr->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'requested') {
                throw DomainException::make('invalid_state_transition', 409, ['from' => __('status.'.$r->status)]);
            }
            $r->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $staff->id, 'decided_at' => now()]);
            $this->orders->history(Order::findOrFail($r->order_id), null, 'return', 'requested', $r->status, $staff, $note, ['return' => $r->number]);
            $this->audit->log('return.'.($approve ? 'approved' : 'rejected'), $r, ['status' => 'requested'], ['status' => $r->status], ['note' => $note], branchId: $r->branch_id, actor: $staff);

            return $r;
        });
    }

    /** Goods received back at the branch; restocked lines are re-added to on-hand as customer_return. */
    public function receive(ReturnRequest $rr, User $staff, bool $restock = true): ReturnRequest
    {
        $this->authorize($staff, 'orders.edit', $rr);

        return DB::transaction(function () use ($rr, $staff, $restock) {
            $r = ReturnRequest::whereKey($rr->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'approved') {
                throw DomainException::make('invalid_state_transition', 409, ['from' => __('status.'.$r->status)]);
            }
            foreach ($r->items as $line) {
                $item = OrderItem::whereKey($line['order_item_id'])->where('order_id', $r->order_id)->lockForUpdate()->firstOrFail();
                $qty = (int) $line['quantity'];
                if ($qty < 1 || $item->quantity_returned + $qty > $item->quantity_handed_over) {
                    throw DomainException::make('quantity_invalid');
                }
                if ($restock && $item->product_variant_id) {
                    $this->ledger->apply($item->branch_id, $item->product_variant_id, MovementType::CustomerReturn, $qty, 0, 'return', $r->id, 'Return '.$r->number, $staff->id);
                }
                $item->update(['quantity_returned' => $item->quantity_returned + $qty]);
            }
            $r->update(['status' => 'received', 'received_at' => now()]);
            $this->orders->history(Order::findOrFail($r->order_id), null, 'return', 'approved', 'received', $staff, null, ['return' => $r->number, 'restocked' => $restock]);
            $this->audit->log('return.received', $r, ['status' => 'approved'], ['status' => 'received'], ['restocked' => $restock], branchId: $r->branch_id, actor: $staff);

            return $r;
        });
    }

    /** Raise the refund for a received return (dual-controlled approval happens in RefundService). */
    public function refund(ReturnRequest $rr, User $staff, Money $amount, string $method = 'original'): Refund
    {
        if ($rr->status !== 'received' || $rr->refund_id) {
            throw DomainException::make('invalid_state_transition', 409, ['from' => __('status.'.$rr->status)]);
        }
        $payment = Payment::where('order_id', $rr->order_id)->whereIn('status', ['paid', 'partially_refunded'])->latest('attempt')->first()
            ?? throw DomainException::make('refund_payment_not_captured', 409);

        return DB::transaction(function () use ($rr, $staff, $amount, $method, $payment) {
            $refund = app(RefundService::class)->request($payment, $amount, 'return', 'Return '.$rr->number, $staff, 'return:'.$rr->id, $method);
            $rr->update(['refund_id' => $refund->id, 'status' => 'refunded']);
            $this->audit->log('return.refund_requested', $rr, [], ['refund' => $refund->number, 'amount' => $refund->amount], branchId: $rr->branch_id, actor: $staff);

            return $refund;
        });
    }

    public function close(ReturnRequest $rr, User $staff): ReturnRequest
    {
        $this->authorize($staff, 'orders.approve', $rr);
        if (! in_array($rr->status, ['received', 'refunded'], true)) {
            throw DomainException::make('invalid_state_transition', 409, ['from' => __('status.'.$rr->status)]);
        }
        $from = $rr->status;
        $rr->update(['status' => 'closed']);
        $this->audit->log('return.closed', $rr, ['status' => $from], ['status' => 'closed'], branchId: $rr->branch_id, actor: $staff);

        return $rr;
    }

    private function authorize(User $staff, string $permission, ReturnRequest $rr): void
    {
        if (! $this->access->allows($staff, $permission, $rr->branch_id)) {
            throw DomainException::make('branch_forbidden', 403);
        }
    }
}
