<?php

namespace App\Domain\Orders\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\ReturnRequest;
use App\Domain\Orders\Models\ReturnRequestEvent;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\Services\RefundService;
use App\Support\DomainException;
use App\Support\Money;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The team's side of a return or damage claim: requested → under_review → approved | rejected; approved → received (goods back at the branch,
 * restocked or written off) → refunded (refund raised via RefundService, with its own approval) → closed. A claim whose goods never come back
 * (`return_method` none: an item missing from the box, a piece the customer keeps) goes from approved straight to the refund.
 *
 * Starting a review and receiving need orders.edit at the claim's branch, deciding needs orders.approve, and the refund goes through the existing
 * refund flow with its own dual control (nothing here pays money out). Every step is audited, written to the claim's own log and the order's
 * history, and tells the customer (push and inbox) through the notification catalogue, so the admin can switch any of those messages off.
 */
class ReturnService
{
    /** How the goods come back. */
    public const METHODS = ['pickup', 'branch', 'none'];

    public function __construct(
        private readonly AccessControl $access,
        private readonly InventoryLedger $ledger,
        private readonly OrderService $orders,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    /** A team member starts looking at the claim: the customer sees "Under review". Repeating it changes nothing. */
    public function startReview(ReturnRequest $rr, User $staff): ReturnRequest
    {
        $this->authorize($staff, 'orders.edit', $rr);

        return DB::transaction(function () use ($rr, $staff) {
            $r = ReturnRequest::whereKey($rr->id)->lockForUpdate()->firstOrFail();
            if ($r->status === 'under_review') {
                return $r;
            }
            $this->mustBe($r, ['requested']);
            $r->update(['status' => 'under_review', 'review_started_at' => now(), 'review_started_by' => $staff->id]);
            $this->log($r, 'review_started', $staff);
            $this->orders->history(Order::findOrFail($r->order_id), null, 'return', 'requested', 'under_review', $staff, null, ['return' => $r->number]);
            $this->audit->log('return.review_started', $r, ['status' => 'requested'], ['status' => 'under_review'], branchId: $r->branch_id, actor: $staff);
            $this->tell($r, 'return.under_review');

            return $r;
        });
    }

    /**
     * Approve (choosing how the goods come back) or reject (a reply for the customer is required). $note is the reply shown to the customer.
     *
     * @param  string|null  $method  pickup | branch | none; null picks `none` for a missing item and `branch` for everything else
     */
    public function decide(ReturnRequest $rr, User $staff, bool $approve, ?string $note = null, ?string $method = null, ?string $methodNote = null): ReturnRequest
    {
        $this->authorize($staff, 'orders.approve', $rr);
        $note = $this->clean($note, 1000);
        if (! $approve && mb_strlen((string) $note) < 3) {
            throw DomainException::make('claim_reply_required');
        }
        if ($approve && $method !== null && ! in_array($method, self::METHODS, true)) {
            throw DomainException::make('claim_method_invalid');
        }

        return DB::transaction(function () use ($rr, $staff, $approve, $note, $method, $methodNote) {
            $r = ReturnRequest::whereKey($rr->id)->lockForUpdate()->firstOrFail();
            $this->mustBe($r, ReturnRequest::UNDECIDED);
            $from = $r->status;
            $method = $approve ? ($method ?? ($r->reason_code === 'missing_item' ? 'none' : 'branch')) : null;
            $r->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $staff->id, 'decided_at' => now(), 'decision_note' => $note,
                'return_method' => $method, 'method_note' => $method === 'none' ? null : $this->clean($methodNote, 300)]);
            $this->log($r, $approve ? 'approved' : 'rejected', $staff, $note, array_filter(['method' => $method]));
            $this->orders->history(Order::findOrFail($r->order_id), null, 'return', $from, $r->status, $staff, $note, ['return' => $r->number]);
            $this->audit->log('return.'.($approve ? 'approved' : 'rejected'), $r, ['status' => $from], ['status' => $r->status], ['note' => $note, 'method' => $method], branchId: $r->branch_id, actor: $staff);
            $this->tell($r, $approve ? 'return.approved' : 'return.rejected', fn (string $locale) => [
                'how' => $approve ? $this->how($r, $locale) : '',
                'note' => $note !== null ? __('returns.note_prefix', ['note' => $note], $locale) : '',
            ]);

            return $r;
        });
    }

    /**
     * Goods received back at the branch. The manager chooses what happens to them: put back in stock (`customer_return` movement) or write off.
     * A damaged or defective item is NEVER restocked unless the manager says so: with no choice made, a claim about the condition of the goods
     * (damaged, wrong, missing, quality) is written off and every other kind goes back on the shelf.
     */
    public function receive(ReturnRequest $rr, User $staff, ?bool $restock = null): ReturnRequest
    {
        $this->authorize($staff, 'orders.edit', $rr);

        return DB::transaction(function () use ($rr, $staff, $restock) {
            $r = ReturnRequest::whereKey($rr->id)->lockForUpdate()->firstOrFail();
            $this->mustBe($r, ['approved']);
            if ($r->return_method === 'none') {
                throw DomainException::make('invalid_state_transition', 409, ['from' => __('status.approved')]);
            }
            $restock ??= ! $r->isDefect();
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
            $r->update(['status' => 'received', 'received_at' => now(), 'stock_decision' => $restock ? 'restock' : 'write_off']);
            $this->log($r, 'received', $staff, null, ['stock' => $r->stock_decision]);
            $this->orders->history(Order::findOrFail($r->order_id), null, 'return', 'approved', 'received', $staff, null, ['return' => $r->number, 'restocked' => $restock]);
            $this->audit->log('return.received', $r, ['status' => 'approved'], ['status' => 'received'], ['restocked' => $restock], branchId: $r->branch_id, actor: $staff);
            $this->tell($r, 'return.item_received');

            return $r;
        });
    }

    /** Raise the refund for a received return, or for an approved claim whose goods never come back (dual-controlled approval happens in RefundService). */
    public function refund(ReturnRequest $rr, User $staff, Money $amount, string $method = 'original'): Refund
    {
        $ready = $rr->status === 'received' || ($rr->status === 'approved' && $rr->return_method === 'none');
        if (! $ready || $rr->refund_id) {
            throw DomainException::make('invalid_state_transition', 409, ['from' => __('status.'.$rr->status)]);
        }
        $payment = Payment::where('order_id', $rr->order_id)->whereIn('status', ['paid', 'partially_refunded'])->latest('attempt')->first()
            ?? throw DomainException::make('refund_payment_not_captured', 409);

        return DB::transaction(function () use ($rr, $staff, $amount, $method, $payment) {
            $refund = app(RefundService::class)->request($payment, $amount, 'return', 'Return '.$rr->number, $staff, 'return:'.$rr->id, $method);
            $rr->update(['refund_id' => $refund->id, 'status' => 'refunded']);
            $this->log($rr, 'refund_requested', $staff, null, ['refund' => $refund->number, 'amount' => $refund->amount]);
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
        $this->log($rr, 'closed', $staff);
        $this->audit->log('return.closed', $rr, ['status' => $from], ['status' => 'closed'], branchId: $rr->branch_id, actor: $staff);

        return $rr;
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @param list<string> $allowed */
    private function mustBe(ReturnRequest $r, array $allowed): void
    {
        if (! in_array($r->status, $allowed, true)) {
            throw DomainException::make('invalid_state_transition', 409, ['from' => __('status.'.$r->status)]);
        }
    }

    /** @param array<string, mixed> $meta */
    private function log(ReturnRequest $r, string $kind, User $actor, ?string $note = null, array $meta = []): void
    {
        ReturnRequestEvent::create(['return_request_id' => $r->id, 'kind' => $kind, 'actor_type' => 'staff', 'actor_id' => $actor->id, 'note' => $note, 'meta' => $meta ?: null]);
    }

    /** The sentence that says what happens to the goods, in the customer's language. */
    private function how(ReturnRequest $r, string $locale): string
    {
        $branch = $r->branch;
        $name = $branch === null ? '' : ($locale === 'ar' ? ($branch->name_ar ?: $branch->name_en) : $branch->name_en);
        $how = __('returns.how.'.($r->return_method ?? 'branch'), ['branch' => $name], $locale);
        $extra = $r->method_note !== null && $r->method_note !== '' && $r->return_method !== 'none' ? ' '.$r->method_note : '';

        return $how.$extra;
    }

    /**
     * Tells the customer (push and inbox, as the notification controls allow). Never throws into the staff action: a customer who cannot be reached
     * does not stop the decision.
     *
     * @param  Closure(string): array<string, string>|null  $perLocale  placeholders that differ by language
     */
    private function tell(ReturnRequest $r, string $event, ?Closure $perLocale = null): void
    {
        $user = User::find($r->user_id);
        $order = Order::find($r->order_id);
        if ($user === null || $order === null) {
            return;
        }
        $by = [];
        foreach ((array) config('platform.supported_locales') as $locale) {
            $by[$locale] = $perLocale ? $perLocale($locale) : [];
        }
        $data = ['order_number' => $order->number, 'return_number' => $r->number, 'deep_link' => 'albahar://orders/'.$order->id, 'reference' => $order->id,
            'how' => '', 'note' => ''] + ['by_locale' => $by];
        $data = array_merge($data, $by[$user->preferredLocale()] ?? []);
        rescue(fn () => $this->notifications->notify($user, $event, $data), report: true);
    }

    private function clean(?string $s, int $max): ?string
    {
        $s = $s !== null ? trim($s) : null;

        return $s === null || $s === '' ? null : Str::limit($s, $max, '');
    }

    private function authorize(User $staff, string $permission, ReturnRequest $rr): void
    {
        if (! $this->access->allows($staff, $permission, $rr->branch_id)) {
            throw DomainException::make('branch_forbidden', 403);
        }
    }
}
