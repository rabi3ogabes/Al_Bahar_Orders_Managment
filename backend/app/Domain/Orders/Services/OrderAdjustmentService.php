<?php

namespace App\Domain\Orders\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Catalog\Contracts\ProductSearch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Checkout\Services\ExtraDeliveryFee;
use App\Domain\Deals\Services\DealService;
use App\Domain\Fulfillment\Enums\DeliveryStatus as DS;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Services\DeliveryService;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\Models\BranchInventory;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Inventory\Services\ReservationService;
use App\Domain\Marketing\Models\CouponUsage;
use App\Domain\Marketing\Models\OrderCoupon;
use App\Domain\Marketing\Services\LoyaltyService;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Enums\OrderStatus as OS;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Models\OrderInvoice;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\OrderRevision;
use App\Domain\Orders\Models\OrderRevisionLine;
use App\Domain\Orders\Realtime\OrderRealtime;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\Services\RefundService;
use App\Domain\Pricing\Data\EffectivePrice;
use App\Domain\Pricing\Services\PriceResolver;
use App\Support\DomainException;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The branch finds an item short while preparing an order: per item it reduces the quantity, replaces the item with another one the branch has,
 * or removes it (docs/api/ORDER_ADJUSTMENTS.md). One call = one revision: any number of item changes, one reason, one notification, one invoice revision.
 *
 * THE ONE RULE: the customer is never charged more because of a shortage, and never loses anything silently. Totals follow the lines, fees stay,
 * a substitute never costs more than what it replaces, the customer is always told, and money that was already paid comes back through a normal
 * refund request that finance approves (nothing is paid out here).
 *
 * Two phases, so a preview is exactly what an apply does: plan() reads (and, when applying, locks) and decides everything without writing;
 * persist() then writes it all in the same transaction. A repeated request finds the lines already changed and is refused as `no_change`.
 *
 * Lock order (same as OrderService::transition, so the two never wait on each other in a circle): the fulfillment, the order, that fulfillment's
 * lines, then the stock rows in (branch, variant) order.
 */
class OrderAdjustmentService
{
    /** At most this many item changes in one request. */
    public const MAX_CHANGES = 50;

    /** A retry of a request whose answer was lost is answered with the revision it created for this long (see replayOf()). */
    public const REPLAY_WINDOW_MINUTES = 10;

    public function __construct(
        private readonly ReservationService $reservations,
        private readonly InventoryLedger $ledger,
        private readonly OrderService $orders,
        private readonly PriceResolver $prices,
        private readonly ProductSearch $search,
        private readonly AccessControl $access,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly RefundService $refunds,
        private readonly LoyaltyService $loyalty,
        private readonly InvoiceService $invoices,
        private readonly OrderPresenter $presenter,
    ) {}

    // ─── Staff reads ────────────────────────────────────────────────────────────────────────────────────────────────

    /** What the staff app / dashboard shows before the change: the parcel's lines, whether it can still be adjusted, the reasons to choose from. */
    public function context(OrderFulfillment $f, User $actor): array
    {
        $this->authorize($actor, $f);
        $order = Order::findOrFail($f->order_id);
        $blocked = $this->blockedReason($f, $order);

        return [
            'can_adjust' => $blocked === null,
            'blocked_reason' => $blocked,
            'reasons' => OrderRevision::REASONS,
            'items' => OrderItem::where('order_fulfillment_id', $f->id)->where('quantity', '>', 0)->orderBy('id')->get()->map(fn (OrderItem $i) => [
                'order_item_id' => $i->id, 'product_id' => $i->product_id, 'variant_id' => $i->product_variant_id, 'sku' => $i->sku,
                'name' => $i->name(), 'variant_name' => $i->variantName(), 'image_url' => $this->presenter->imageUrl($i->image_path),
                'quantity' => $i->quantity, 'unit_price' => Money::of($i->unit_final_price)->jsonSerialize(), 'line_total' => Money::of($i->line_total)->jsonSerialize(),
                'adjustment' => $i->adjustment_kind ? ['kind' => $i->adjustment_kind, 'original_quantity' => $i->original_quantity ?? $i->quantity, 'reason_code' => $i->adjustment_reason] : null,
            ])->values()->all(),
            'totals' => ['grand_total' => Money::of($order->grand_total)->jsonSerialize(), 'payment_method' => $order->payment_method, 'payment_status' => $order->payment_status->value],
        ];
    }

    /**
     * Variants the branch has in stock and may sell, other than the lines already in the parcel: what can take the place of a missing item.
     *
     * @return list<array<string, mixed>>
     */
    public function substitutes(OrderFulfillment $f, User $actor, string $search = '', int $limit = 20): array
    {
        $this->authorize($actor, $f);
        $limit = max(1, min(50, $limit));
        $branch = $f->branch()->firstOrFail();
        $inParcel = OrderItem::where('order_fulfillment_id', $f->id)->where('quantity', '>', 0)->whereNotNull('product_variant_id')->pluck('product_variant_id')->all();
        $stocked = fn ($inventory) => $inventory->from('branch_inventory as bi')->whereColumn('bi.product_variant_id', 'product_variants.id')
            ->where('bi.branch_id', $branch->id)->whereRaw('bi.on_hand - bi.reserved > 0');

        $q = Product::query()->active()->whereHas('variants', fn ($v) => $v->where('product_variants.status', 'active')->whereNotIn('product_variants.id', $inParcel)->whereExists($stocked));
        if (trim($search) !== '') {
            $this->search->apply($q, $search);
        } else {
            $q->orderByDesc('is_bestseller')->orderByDesc('sales_count');
        }
        $products = $q->with(['translations', 'images', 'variants' => fn ($v) => $v->where('status', 'active')->whereNotIn('id', $inParcel)->with(['attributeValues', 'images'])])
            ->limit($limit)->get();

        $variants = $products->flatMap->variants;
        $this->prices->prime($variants, $branch->id);
        $available = BranchInventory::where('branch_id', $branch->id)->whereIn('product_variant_id', $variants->pluck('id'))->get()->keyBy('product_variant_id');

        $rows = [];
        foreach ($products as $p) {
            foreach ($p->variants as $v) {
                $v->setRelation('product', $p);
                $free = $available->get($v->id)?->available() ?? 0;
                $price = $free > 0 ? $this->prices->resolve($v, $branch) : null;
                if ($price === null) {
                    continue;
                }
                $rows[] = ['variant_id' => $v->id, 'product_id' => $p->id, 'name' => (string) $p->trans('name'), 'variant_name' => $v->displayName(), 'sku' => $v->sku,
                    'image_url' => ($v->images->first() ?? $p->images->first())?->url(320), 'unit_price' => $price->final()->jsonSerialize(), 'available' => $free];
                if (count($rows) >= $limit) {
                    break 2;
                }
            }
        }

        return $rows;
    }

    // ─── The change ─────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Reduce, replace or remove items of the branch's parcel while it is accepted or being prepared.
     *
     * @param  list<array{order_item_id: string, action: string, new_quantity?: int, variant_id?: string, quantity?: int}>  $changes
     * @return array<string, mixed> the contract's `data` object (preview: nothing was written)
     */
    public function adjust(OrderFulfillment $fulfillment, User $actor, array $changes, string $reasonCode, ?string $note = null, bool $preview = false): array
    {
        $this->authorize($actor, $fulfillment);
        $this->assertShape($changes, $reasonCode);
        $note = $note !== null && trim($note) !== '' ? Str::limit(trim($note), 500, '') : null;

        if ($preview) {
            return $this->present($this->plan($fulfillment->id, $actor, $changes, $reasonCode, lock: false), null);
        }

        $hash = $this->requestHash($fulfillment->id, $changes, $reasonCode, $note);

        // Three attempts: a deadlock with a customer's cancellation (which takes the same rows in the other order) retries; the loser then finds the fulfillment cancelled.
        return DB::transaction(fn () => $this->apply($fulfillment->id, $actor, $changes, $reasonCode, $note, $hash), 3);
    }

    /** @return array<string, mixed> */
    private function apply(string $fulfillmentId, User $actor, array $changes, string $reasonCode, ?string $note, string $hash): array
    {
        // The same request again, soon after it was applied (the app never saw the answer): the answer it already earned, nothing written, nobody told twice.
        if (($replay = $this->replayOf($fulfillmentId, $actor, $hash)) !== null) {
            return $replay;
        }
        app(DealService::class)->lockForFulfillment($fulfillmentId); // deal rows before the stock rows plan() locks, the same order checkout uses
        $plan = $this->plan($fulfillmentId, $actor, $changes, $reasonCode, lock: true);
        /** @var Order $order */
        $order = $plan['order'];
        /** @var OrderFulfillment $f */
        $f = $plan['fulfillment'];
        $revisionId = (new OrderRevision)->newUniqueId();
        $number = $plan['number'];
        $after = $plan['after'];

        // 1. The lines. Substitutes are created first (they need an id to be reserved under), then each original line.
        $newItems = [];
        foreach ($plan['changes'] as $c) {
            if ($c['sub'] !== null) {
                $newItems[$c['item']->id] = $this->createSubstituteLine($order, $f, $c, $reasonCode);
            }
        }
        $stock = [];
        foreach ($plan['changes'] as $c) {
            /** @var OrderItem $item */
            $item = $c['item'];
            $stock[$item->id] = $this->releaseStock($item, $c['removed'], $c['to'] > 0, $reasonCode, $revisionId, $actor);
            $item->forceFill([
                'quantity' => $c['to'], 'line_subtotal' => $c['new']['subtotal']->toDecimal(), 'discount_amount' => $c['new']['discount']->toDecimal(),
                'tax_amount' => $c['new']['tax']->toDecimal(), 'line_total' => $c['new']['total']->toDecimal(), 'original_quantity' => $item->original_quantity ?? $item->quantity,
                'adjustment_kind' => $c['action'] === 'reduce' ? ($item->adjustment_kind === 'replacement' ? 'replacement' : 'reduced') : ($c['action'] === 'replace' ? 'replaced' : 'removed'),
                'adjustment_reason' => $reasonCode,
            ])->save();
            if ($c['sub'] !== null) {
                $this->reserveSubstitute($newItems[$item->id], $actor);
            }
            // The line's deal units shrink with its quantity (a substitute keeps the share it carries); the units no line holds go back to the deal below.
            app(DealService::class)->adjusted($item, $c['from'], $c['to'], $newItems[$item->id] ?? null, (int) ($c['sub']['quantity'] ?? 0));
        }
        app(DealService::class)->release($order->id);

        // 2. Money on the order, its parcel, its discounts and its payment.
        $refundId = null;
        $refundAmount = null;
        if (! $plan['order_cancelled']) {
            $this->writeTotals($order, $f, $plan, $number);
            $this->rescaleDiscounts($order, $after['item_discount'], $after['delivery_discount']);
            if ($plan['loyalty_returned'] > 0 && $order->user) {
                $this->loyalty->returnRedeemed($order->user, $plan['loyalty_returned'], $order->id, 'Order items changed: unused points returned');
            }
            $this->syncPayment($order, $after['grand_total']);
            if ($plan['refund'] !== null) {
                $refund = $this->refunds->request($plan['payment'], $plan['refund']['amount'], 'order_adjustment', 'Order '.$order->number.' items changed (revision '.$number.')', $actor,
                    'adjust:'.$order->id.':'.$number, system: true);
                $refundId = $refund->id;
                $refundAmount = $plan['refund']['amount'];
            }
        } else {
            $order->forceFill(['adjustment_count' => $number])->save();
        }

        // 3. The timeline entry the customer sees comes before whatever the cancellation of an empty parcel adds after it.
        $this->orders->history($order, $f, 'items_changed', null, 'items_changed', $actor, $reasonCode,
            ['revision' => $number, 'difference' => $plan['difference']->toDecimal(), 'lines' => count($plan['changes'])], visible: true);
        if ($plan['order_cancelled']) {
            // Nothing is left of the order: the normal cancellation does the rest (stock, slots, the refund of everything paid, loyalty points, coupon uses).
            $this->orders->cancelOrder($order, $actor, 'order_adjustment', $note, system: true);
            $refund = Refund::where('order_id', $order->id)->where('reason_code', 'order_cancelled')->orderByDesc('created_at')->first();
            $refundId = $refund?->id;
            $refundAmount = $refund ? Money::of($refund->amount) : null;
        } elseif ($plan['fulfillment_empty']) {
            $this->cancelEmptyFulfillment($f, $actor, $number);
        }

        // 4. The audit row: append-only, so it is written once everything it points at (the refund) exists.
        $revision = new OrderRevision([
            'order_id' => $order->id, 'order_fulfillment_id' => $f->id, 'branch_id' => $f->branch_id, 'number' => $number, 'actor_id' => $actor->id,
            'reason_code' => $reasonCode, 'note' => $note, 'before' => $this->totalsArray($plan['before']), 'after' => $this->totalsArray($plan['after_totals']),
            'difference' => $plan['difference']->toDecimal(), 'refund_id' => $refundId, 'refund_amount' => $refundAmount?->toDecimal(), 'request_hash' => $hash,
            'loyalty_points_returned' => $plan['loyalty_returned'], 'fulfillment_cancelled' => $plan['fulfillment_empty'], 'order_cancelled' => $plan['order_cancelled'],
        ]);
        $revision->forceFill(['id' => $revisionId]);
        $revision->save();
        foreach ($plan['changes'] as $position => $c) {
            $item = $c['item'];
            $sub = $c['sub'];
            OrderRevisionLine::create([
                'order_revision_id' => $revisionId, 'position' => $position, 'action' => $c['action'], 'order_item_id' => $item->id,
                'replacement_order_item_id' => isset($newItems[$item->id]) ? $newItems[$item->id]->id : null, 'product_variant_id' => $item->product_variant_id,
                'sku' => $item->sku, 'name_en' => $item->name_en, 'name_ar' => $item->name_ar, 'variant_name_en' => $item->variant_name_en, 'variant_name_ar' => $item->variant_name_ar,
                'from_quantity' => $c['from'], 'to_quantity' => $c['to'], 'unit_price' => $item->unit_final_price,
                'line_total_before' => $c['old']['total']->toDecimal(), 'line_total_after' => $c['new']['total']->toDecimal(),
                'stock_corrected' => $stock[$item->id],
                'replacement' => $sub === null ? null : $this->replacementSnapshot($sub, $newItems[$item->id]),
            ]);
        }
        $revision->load('lines');
        $this->audit->log('order.items_adjusted', $order, $this->totalsArray($plan['before']), $this->totalsArray($plan['after_totals']), [
            'revision' => $number, 'reason_code' => $reasonCode, 'note' => $note, 'fulfillment' => $f->number, 'refund_amount' => $refundAmount?->toDecimal(),
            'order_cancelled' => $plan['order_cancelled'], 'lines' => $revision->lines->map(fn (OrderRevisionLine $l) => $l->only(['action', 'sku', 'from_quantity', 'to_quantity', 'stock_corrected']))->all(),
        ], branchId: $f->branch_id, actor: $actor);

        // 5. A new invoice revision when one was issued already, the live screens, and the customer's notification.
        $invoice = $plan['order_cancelled'] ? null : $this->invoices->reviseIfIssued($order);
        app(OrderRealtime::class)->fulfillmentChanged($f->fresh());
        $notified = $this->notifyCustomer($order->fresh(), $revision, $refundAmount, $plan['order_cancelled'], $after['grand_total']);

        $plan['refund'] = $refundAmount ? ['amount' => $refundAmount, 'needs_approval' => true] : null;

        return $this->present($plan, $revision, $notified, $invoice?->revision);
    }

    /**
     * Replay safety. The staff app taps again when it never saw the answer. An identical request (same actor, same parcel, same changes, reason and note)
     * made within REPLAY_WINDOW_MINUTES of the revision it created, while the order still is exactly what that revision left, is answered with that
     * revision's result: 200, `replayed: true`, nothing written, nobody notified again. Anything else (another person, other changes, later, or the order
     * moved on) is a new request and meets the normal rules, so `no_change` still says "this is already in place". Runs under the same locks as plan(),
     * so a retry that overtakes its own first attempt waits for it and is answered with it.
     *
     * @return array<string, mixed>|null
     */
    private function replayOf(string $fulfillmentId, User $actor, string $hash): ?array
    {
        $f = OrderFulfillment::whereKey($fulfillmentId)->lockForUpdate()->firstOrFail();
        $order = Order::whereKey($f->order_id)->lockForUpdate()->firstOrFail();
        if ($order->adjustment_count < 1) {
            return null;
        }
        $revision = OrderRevision::with('lines')->where('order_id', $order->id)->where('number', $order->adjustment_count)->first();
        if ($revision === null || $revision->order_fulfillment_id !== $f->id || $revision->actor_id !== $actor->id || $revision->request_hash !== $hash
            || $revision->created_at->lt(now()->subMinutes(self::REPLAY_WINDOW_MINUTES)) || ! $this->orderStillAsLeftBy($revision, $order)) {
            return null;
        }

        return $this->presentReplay($revision, $order);
    }

    /** The order's money (or, for a revision that cancelled it, its status) is still what the revision made it. */
    private function orderStillAsLeftBy(OrderRevision $revision, Order $order): bool
    {
        if ($revision->order_cancelled) {
            return $order->status === OS::Cancelled;
        }
        foreach (['subtotal', 'discount_total', 'tax_total', 'grand_total', 'loyalty_discount'] as $field) {
            if (! Money::of($order->{$field})->equals(Money::of($revision->after[$field] ?? '0'))) {
                return false;
            }
        }

        return true;
    }

    /** The result the first request got, rebuilt from the revision (names in the caller's language). @return array<string, mixed> */
    private function presentReplay(OrderRevision $revision, Order $order): array
    {
        $m = fn (mixed $v) => Money::of((string) ($v ?? '0'))->jsonSerialize();
        $totals = fn (array $t) => ['subtotal' => $m($t['subtotal'] ?? null), 'discount_total' => $m($t['discount_total'] ?? null), 'tax_total' => $m($t['tax_total'] ?? null), 'grand_total' => $m($t['grand_total'] ?? null)];

        return [
            'applied' => true, 'preview' => false, 'replayed' => true,
            'revision' => ['id' => $revision->id, 'number' => $revision->number],
            'lines' => $revision->lines->map(fn (OrderRevisionLine $l) => ['order_item_id' => $l->order_item_id, 'action' => $l->action, 'name' => $l->name(), 'variant_name' => $l->variantName(),
                'from_quantity' => $l->from_quantity, 'to_quantity' => $l->to_quantity,
                'replacement' => $l->replacement === null ? null : ['variant_id' => $l->replacement['variant_id'] ?? null, 'name' => $l->replacementName(), 'variant_name' => $l->replacementVariantName(),
                    'quantity' => (int) ($l->replacement['quantity'] ?? 0), 'unit_price' => $m($l->replacement['unit_price'] ?? null)]])->values()->all(),
            'before' => $totals($revision->before), 'after' => $totals($revision->after), 'difference' => $m($revision->difference),
            'refund' => $revision->refund_amount !== null ? ['amount' => $m($revision->refund_amount), 'needs_approval' => true] : null,
            'fulfillment_cancelled' => $revision->fulfillment_cancelled, 'order_cancelled' => $revision->order_cancelled,
            'customer_notified' => $order->user_id !== null && Notification::where('user_id', $order->user_id)->where('event', 'order.items_changed')
                ->where('data->reference', $order->id)->where('created_at', '>=', $revision->created_at)->exists(),
            'invoice_revision' => OrderInvoice::where('order_id', $order->id)->where('revision', '>', 1)->where('issued_at', '>=', $revision->created_at)->min('revision'),
            'loyalty_points_returned' => $revision->loyalty_points_returned,
        ];
    }

    /** Identifies a request regardless of the order its changes were listed in or how it was spelt (a trimmed note, an integer sent as text). */
    private function requestHash(string $fulfillmentId, array $changes, string $reasonCode, ?string $note): string
    {
        $rows = collect($changes)->map(fn (array $c) => [strtolower((string) $c['order_item_id']), (string) $c['action'], isset($c['new_quantity']) ? (int) $c['new_quantity'] : null,
            isset($c['variant_id']) ? strtolower((string) $c['variant_id']) : null, isset($c['quantity']) ? (int) $c['quantity'] : null])->sortBy(0)->values()->all();

        return hash('sha256', json_encode([$fulfillmentId, $rows, $reasonCode, $note], JSON_THROW_ON_ERROR));
    }

    // ─── Planning (reads and decides, never writes) ─────────────────────────────────────────────────────────────────

    /**
     * Validate the request against the CURRENT state and work out everything it would do. With $lock the rows are locked for update (the caller
     * is inside the transaction that will write); without, it is a plain read for a preview.
     *
     * @return array<string, mixed>
     */
    private function plan(string $fulfillmentId, User $actor, array $changes, string $reasonCode, bool $lock): array
    {
        $locked = fn ($query) => $lock ? $query->lockForUpdate() : $query;
        /** @var OrderFulfillment $f */
        $f = $locked(OrderFulfillment::whereKey($fulfillmentId))->firstOrFail();
        /** @var Order $order */
        $order = $locked(Order::whereKey($f->order_id))->firstOrFail();
        if ($this->blockedReason($f, $order) !== null) {
            throw DomainException::make('adjustment_not_allowed', 409);
        }
        $cur = $order->currency ?: 'QAR';
        $zero = Money::zero($cur);

        // The parcel's lines (locked) and the rest of the order's lines (only read: they decide the totals).
        $parcel = $locked(OrderItem::where('order_fulfillment_id', $f->id)->orderBy('id'))->get()->keyBy('id');
        $rest = OrderItem::where('order_id', $order->id)->where('order_fulfillment_id', '!=', $f->id)->get();

        $resolved = $this->resolve($changes, $parcel, $f, $reasonCode, $lock);
        $number = $order->adjustment_count + 1;

        // Money per line.
        $dSubtotal = $dDiscount = $dTax = $zero;
        $liveAfter = $parcel->where('quantity', '>', 0)->sum('quantity');
        foreach ($resolved as &$c) {
            /** @var OrderItem $item */
            $item = $c['item'];
            $unit = Money::of($item->unit_final_price, $cur);
            $rate = (string) $item->tax_rate;
            $c['old'] = ['subtotal' => Money::of($item->line_subtotal, $cur), 'discount' => Money::of($item->discount_amount, $cur), 'tax' => Money::of($item->tax_amount, $cur), 'total' => Money::of($item->line_total, $cur)];
            $c['new'] = $c['to'] === 0 ? AdjustmentMath::line($unit, 0, $zero, $rate) : AdjustmentMath::reduced($unit, $c['from'], $c['to'], $c['old']['discount'], $rate);
            $dSubtotal = $dSubtotal->plus($c['new']['subtotal'])->minus($c['old']['subtotal']);
            $dDiscount = $dDiscount->plus($c['new']['discount'])->minus($c['old']['discount']);
            $dTax = $dTax->plus($c['new']['tax'])->minus($c['old']['tax']);
            $liveAfter -= $c['removed'];
            if ($c['sub'] !== null) {
                $price = $c['sub']['price'];
                $c['sub']['line'] = AdjustmentMath::substitute($unit, $c['from'], $c['old']['discount'], $price->final(), $c['sub']['quantity'], $rate);
                $c['sub']['unit'] = $c['sub']['line']['unit'];
                $dSubtotal = $dSubtotal->plus($c['sub']['line']['subtotal']);
                $dDiscount = $dDiscount->plus($c['sub']['line']['discount']);
                $dTax = $dTax->plus($c['sub']['line']['tax']);
                $liveAfter += $c['sub']['quantity'];
            }
        }
        unset($c);

        $fulfillmentEmpty = $liveAfter <= 0;
        $others = OrderFulfillment::where('order_id', $order->id)->where('id', '!=', $f->id)->get(['id', 'status']);
        $orderCancelled = $fulfillmentEmpty && $others->every(fn (OrderFulfillment $o) => $o->status === FS::Cancelled);

        // Order totals before and after, from the same parts the checkout built them from.
        $fee = (array) ($order->fee_breakdown ?? []);
        $deliveryDiscount = Money::of((string) ($fee['delivery_discount'] ?? '0'), $cur);
        $serviceTotal = Money::of($order->service_fee_total, $cur);
        $cod = Money::of((string) ($fee['cod_fee'] ?? '0'), $cur)->min($serviceTotal);
        $beforeParts = [
            'subtotal' => Money::of($order->subtotal, $cur), 'item_discount' => Money::of($order->discount_total, $cur)->minus($deliveryDiscount)->max($zero),
            'delivery_fee' => Money::of($order->delivery_fee_total, $cur), 'delivery_discount' => $deliveryDiscount, 'service_fee' => $serviceTotal->minus($cod),
            'cod_fee' => $cod, 'tax' => Money::of($order->tax_total, $cur), 'loyalty' => Money::of($order->loyalty_discount, $cur),
        ];
        $afterParts = $beforeParts;
        $afterParts['subtotal'] = $beforeParts['subtotal']->plus($dSubtotal)->max($zero);
        $afterParts['item_discount'] = $beforeParts['item_discount']->plus($dDiscount)->max($zero);
        $afterParts['tax'] = $beforeParts['tax']->plus($dTax)->max($zero);
        // The extra delivery fee of a bulky product goes with the product: units that are gone are not charged for delivery (per unit), a product that is
        // gone entirely is not charged at all (once per product). A product that stays costs exactly what it did: this never raises a fee.
        $extra = $this->extraDeliveryAfter($order, $f, $parcel, $resolved, $zero);
        $afterParts['delivery_fee'] = $beforeParts['delivery_fee']->minus($extra['cut']);
        if ($fulfillmentEmpty && ! $orderCancelled) {
            // A parcel that will never be delivered is not charged for its delivery (what is left of it once the bulky-item fees came off).
            $afterParts['delivery_fee'] = $afterParts['delivery_fee']->minus(Money::of($f->delivery_fee, $cur)->minus($extra['cut'])->max($zero)->min($afterParts['delivery_fee']));
        }
        if ($extra['cut']->isPositive() || ($fulfillmentEmpty && ! $orderCancelled)) {
            $afterParts['delivery_discount'] = $beforeParts['delivery_discount']->min($afterParts['delivery_fee']); // a free-delivery coupon waived all of it: it waives what is left
        }
        $payableBeforePoints = $afterParts['subtotal']->minus($afterParts['item_discount'])->plus($afterParts['delivery_fee'])->minus($afterParts['delivery_discount'])
            ->plus($afterParts['service_fee'])->plus($afterParts['tax']);
        $fit = $order->user_id ? AdjustmentMath::loyaltyThatFits((int) $order->loyalty_points_redeemed, $beforeParts['loyalty'], $payableBeforePoints) : ['points' => (int) $order->loyalty_points_redeemed, 'discount' => $beforeParts['loyalty'], 'returned' => 0];
        $afterParts['loyalty'] = $fit['discount'];

        $grandBefore = Money::of($order->grand_total, $cur);
        $grandAfter = $grandBefore->plus(AdjustmentMath::grandTotal($afterParts)->minus(AdjustmentMath::grandTotal($beforeParts)))->max($zero)->min($grandBefore);
        if ($orderCancelled) {
            $grandAfter = $zero;
        }
        $totalsBefore = ['subtotal' => $beforeParts['subtotal'], 'discount_total' => $beforeParts['item_discount']->plus($beforeParts['delivery_discount']), 'tax_total' => $beforeParts['tax'],
            'grand_total' => $grandBefore, 'loyalty_discount' => $beforeParts['loyalty']];
        $totalsAfter = $orderCancelled
            ? ['subtotal' => $zero, 'discount_total' => $zero, 'tax_total' => $zero, 'grand_total' => $zero, 'loyalty_discount' => $zero]
            : ['subtotal' => $afterParts['subtotal'], 'discount_total' => $afterParts['item_discount']->plus($afterParts['delivery_discount']), 'tax_total' => $afterParts['tax'],
                'grand_total' => $grandAfter, 'loyalty_discount' => $afterParts['loyalty']];

        // Money already paid: what comes back (a refund request for finance to approve; nothing is paid out here).
        $payment = Payment::where('order_id', $order->id)->whereIn('status', ['paid', 'partially_refunded'])->orderByDesc('attempt');
        $payment = ($lock ? $payment->lockForUpdate() : $payment)->first();
        $refund = null;
        if ($payment !== null) {
            $due = $orderCancelled ? $payment->refundableNow() : $payment->refundableNow()->minus($grandAfter);
            $refund = $due->isPositive() ? ['amount' => $due, 'needs_approval' => true] : null;
        }

        return [
            'fulfillment' => $f, 'order' => $order, 'number' => $number, 'changes' => $resolved, 'reason_code' => $reasonCode,
            'before' => $totalsBefore, 'after_totals' => $totalsAfter, 'difference' => $grandAfter->minus($grandBefore),
            'after' => ['item_discount' => $afterParts['item_discount'], 'delivery_discount' => $afterParts['delivery_discount'], 'delivery_fee' => $afterParts['delivery_fee'],
                'grand_total' => $grandAfter, 'subtotal' => $afterParts['subtotal'], 'tax' => $afterParts['tax'], 'loyalty' => $afterParts['loyalty']],
            'loyalty_points' => $fit['points'], 'loyalty_returned' => $fit['returned'], 'extra' => $extra,
            'fulfillment_empty' => $fulfillmentEmpty, 'order_cancelled' => $orderCancelled, 'payment' => $payment, 'refund' => $refund,
            'parcel' => $parcel, 'rest' => $rest,
        ];
    }

    /**
     * The bulky-item delivery fees (fee_breakdown.extra_delivery, docs/api/CHECKOUT_FEES_V2.md section 3) of THIS parcel once the changes are made:
     * how much comes off, and the stored lines as they will read. Units still in the parcel (including a substitute of the same product) keep
     * their fee; a fee never goes up. An order without stored fees (placed before the feature, or none owed) changes nothing.
     *
     * @param  Collection<string, OrderItem>  $parcel
     * @param  list<array<string, mixed>>  $resolved
     * @return array{cut: Money, amount: Money, lines: list<array<string, mixed>>}
     */
    private function extraDeliveryAfter(Order $order, OrderFulfillment $f, Collection $parcel, array $resolved, Money $zero): array
    {
        $cur = $zero->currency;
        /** @var list<array<string, mixed>> $stored */
        $stored = array_values((array) data_get($order->fee_breakdown, 'extra_delivery.lines', []));
        $changed = collect($resolved)->keyBy(fn (array $c) => $c['item']->id);
        $left = [];
        foreach ($parcel as $item) {
            $units = $changed->has($item->id) ? (int) $changed[$item->id]['to'] : (int) $item->quantity;
            if ($item->product_id !== null && $units > 0) {
                $left[$item->product_id] = ($left[$item->product_id] ?? 0) + $units;
            }
        }
        foreach ($resolved as $c) {
            if ($c['sub'] !== null) {
                $left[$c['sub']['variant']->product_id] = ($left[$c['sub']['variant']->product_id] ?? 0) + (int) $c['sub']['quantity'];
            }
        }

        $cut = $zero;
        $amount = $zero;
        $lines = [];
        foreach ($stored as $line) {
            $old = Money::of((string) ($line['amount'] ?? '0'), $cur);
            if (($line['branch_id'] ?? null) === $f->branch_id) {
                $units = min((int) ($line['quantity'] ?? 0), $left[(string) ($line['product_id'] ?? '')] ?? 0);
                $new = ExtraDeliveryFee::amount(Money::of((string) ($line['fee_each'] ?? '0'), $cur), (string) ($line['mode'] ?? ExtraDeliveryFee::PER_UNIT), $units)->min($old);
                $cut = $cut->plus($old->minus($new));
                $line = array_merge($line, ['quantity' => $units, 'amount' => $new->toDecimal()]);
                $old = $new;
            }
            $amount = $amount->plus($old);
            $lines[] = $line;
        }

        return ['cut' => $cut, 'amount' => $amount, 'lines' => $lines];
    }

    /**
     * Check every change against the parcel as it is now; the first problem is reported with the change's position.
     *
     * @param  Collection<string, OrderItem>  $parcel
     * @return list<array<string, mixed>>
     */
    private function resolve(array $changes, Collection $parcel, OrderFulfillment $f, string $reasonCode, bool $lock): array
    {
        $fail = fn (string $code, int $index, ?string $itemId, int $status = 422) => throw DomainException::make($code, $status, array_filter(['index' => $index, 'order_item_id' => $itemId], fn ($v) => $v !== null));
        $seen = [];
        $resolved = [];
        foreach (array_values($changes) as $i => $c) {
            $id = (string) $c['order_item_id'];
            if (isset($seen[$id])) {
                $fail('duplicate_item', $i, $id);
            }
            $seen[$id] = true;
            /** @var OrderItem|null $item */
            $item = $parcel->get($id);
            if ($item === null) {
                $fail('item_not_in_fulfillment', $i, $id);
            }
            if ($item->quantity === 0) {
                $fail('no_change', $i, $id); // already removed or swapped out: a repeat of the same request
            }
            $action = (string) $c['action'];
            if ($action === 'reduce') {
                $to = (int) $c['new_quantity'];
                if ($to < 0 || $to > $item->quantity) {
                    $fail('quantity_exceeds', $i, $id);
                }
                if ($to === $item->quantity) {
                    $fail('no_change', $i, $id);
                }
                $action = $to === 0 ? 'remove' : 'reduce';
            } else {
                $to = 0;
            }
            $resolved[] = ['index' => $i, 'item' => $item, 'action' => $action, 'from' => $item->quantity, 'to' => $to, 'removed' => $item->quantity - $to, 'sub' => null,
                'request' => $c];
        }

        // Substitutes: sellable at this branch, in stock for everything asked in this request together, never a line the parcel already has.
        $variantIds = collect($resolved)->filter(fn ($r) => $r['action'] === 'replace')->map(fn ($r) => (string) ($r['request']['variant_id'] ?? ''))->filter()->unique()->values();
        $variants = $variantIds->isEmpty() ? collect() : ProductVariant::with(['product.translations', 'product.images', 'images', 'attributeValues.attribute'])->whereIn('id', $variantIds)->get()->keyBy('id');
        $branch = $f->branch()->firstOrFail();
        $this->prices->prime($variants->values(), $branch->id);
        $inParcel = $parcel->where('quantity', '>', 0)->pluck('product_variant_id')->filter()->all();
        $pairs = collect($resolved)->flatMap(fn ($r) => array_filter([$r['item']->product_variant_id ? [$f->branch_id, $r['item']->product_variant_id] : null,
            $r['action'] === 'replace' && ($v = $variants->get((string) ($r['request']['variant_id'] ?? ''))) ? [$f->branch_id, $v->id] : null]))->values()->all();
        $rows = $lock ? $this->ledger->lockMany($pairs) : BranchInventory::where('branch_id', $f->branch_id)->whereIn('product_variant_id', collect($pairs)->pluck(1))->get()
            ->keyBy(fn ($r) => $r->branch_id.'|'.$r->product_variant_id)->all();

        $wanted = [];
        foreach ($resolved as &$r) {
            if ($r['action'] !== 'replace') {
                continue;
            }
            $req = $r['request'];
            $variant = $variants->get((string) ($req['variant_id'] ?? ''));
            $qty = (int) ($req['quantity'] ?? 0);
            if ($qty < 1 || $qty > $r['from']) {
                $fail('quantity_exceeds', $r['index'], $r['item']->id);
            }
            $price = $variant ? $this->prices->resolve($variant, $branch) : null;
            if (! $variant || ! $price || $variant->id === $r['item']->product_variant_id || in_array($variant->id, $inParcel, true)) {
                $fail('substitute_unavailable', $r['index'], $r['item']->id);
            }
            $wanted[$variant->id] = ($wanted[$variant->id] ?? 0) + $qty;
            $free = ($rows[$f->branch_id.'|'.$variant->id] ?? null)?->available() ?? 0;
            if ($free < $wanted[$variant->id]) {
                $fail('substitute_unavailable', $r['index'], $r['item']->id);
            }
            $r['sub'] = ['variant' => $variant, 'price' => $price, 'quantity' => $qty, 'line' => null, 'unit' => null];
        }
        unset($r);

        return $resolved;
    }

    // ─── Writing ────────────────────────────────────────────────────────────────────────────────────────────────────

    /** The new line for a substitute; its stock is reserved right after the original line is released. */
    private function createSubstituteLine(Order $order, OrderFulfillment $f, array $c, string $reasonCode): OrderItem
    {
        /** @var ProductVariant $v */
        $v = $c['sub']['variant'];
        $p = $v->product;
        /** @var EffectivePrice $price */
        $price = $c['sub']['price'];
        $line = $c['sub']['line'];
        $attributes = $v->attributeValues->map(fn ($av) => ['attribute' => $av->attribute?->code, 'value_en' => $av->value_en, 'value_ar' => $av->value_ar])->values()->all();
        /** @var OrderItem $item */
        $item = $c['item'];

        return OrderItem::create([
            'order_id' => $order->id, 'order_fulfillment_id' => $f->id, 'branch_id' => $f->branch_id, 'product_id' => $p->id, 'product_variant_id' => $v->id, 'sku' => $v->sku,
            'name_en' => $p->trans('name', 'en'), 'name_ar' => $p->trans('name', 'ar') ?? $p->trans('name', 'en'),
            'variant_name_en' => $v->displayName('en'), 'variant_name_ar' => $v->displayName('ar'), 'variant_attributes' => $attributes ?: null,
            'image_path' => ($v->images->first() ?? $p->images->first())?->path,
            'unit_price' => $price->regular->toDecimal(), 'sale_price' => $line['unit']->lessThan($price->regular) ? $line['unit']->toDecimal() : null,
            'unit_final_price' => $line['unit']->toDecimal(), 'quantity' => $line['quantity'], 'line_subtotal' => $line['subtotal']->toDecimal(),
            'discount_amount' => $line['discount']->toDecimal(), 'tax_rate' => $item->tax_rate, 'tax_amount' => $line['tax']->toDecimal(), 'line_total' => $line['total']->toDecimal(),
            'price_source' => 'adjustment', 'original_quantity' => $c['from'], 'adjustment_kind' => 'replacement', 'adjustment_reason' => $reasonCode,
            'replaces_order_item_id' => $item->id,
        ]);
    }

    private function reserveSubstitute(OrderItem $new, User $actor): void
    {
        try {
            $this->reservations->reserve([['branch_id' => $new->branch_id, 'variant_id' => $new->product_variant_id, 'quantity' => $new->quantity,
                'reference_type' => 'order_item', 'reference_id' => $new->id]], null, $actor->id);
        } catch (InsufficientStock) {
            throw DomainException::make('substitute_unavailable', 422); // another sale took the last unit between the check and the reservation
        }
    }

    /**
     * Give the removed units of a line back to the branch's available stock; when the reason says the unit was never on the shelf
     * (out of stock / not found) also take it off the on-hand count, so the shop does not keep selling a phantom unit.
     *
     * @return int units taken off the on-hand count
     */
    private function releaseStock(OrderItem $item, int $units, bool $keepsLine, string $reasonCode, string $revisionId, User $actor): int
    {
        $reservation = $this->reservations->forReference('order_item', $item->id);
        $variantId = $reservation?->product_variant_id ?? $item->product_variant_id;
        if ($reservation !== null && $reservation->status === 'active') {
            $keepsLine ? $this->reservations->reduce($reservation, $units, 'order_adjustment', $actor->id) : $this->reservations->release($reservation, 'order_adjustment', actorId: $actor->id);
        }
        if (! in_array($reasonCode, OrderRevision::STOCK_CORRECTING_REASONS, true) || $variantId === null) {
            return 0;
        }
        $row = BranchInventory::where('branch_id', $item->branch_id)->where('product_variant_id', $variantId)->first();
        // Never below what other orders have reserved: the count cannot drop under it.
        $take = min($units, max(0, ($row?->on_hand ?? 0) - ($row?->reserved ?? 0)));
        if ($take > 0) {
            $this->ledger->apply($item->branch_id, $variantId, MovementType::Correction, -$take, 0, 'order_revision', $revisionId, 'Order adjustment: '.$reasonCode, $actor->id);
        }

        return $take;
    }

    /** @param  array<string, mixed>  $plan */
    private function writeTotals(Order $order, OrderFulfillment $f, array $plan, int $number): void
    {
        $after = $plan['after'];
        $totals = $plan['after_totals'];
        $liveSubtotal = OrderItem::where('order_fulfillment_id', $f->id)->where('quantity', '>', 0)->get()->reduce(fn (Money $c, OrderItem $i) => $c->plus(Money::of($i->line_subtotal)), Money::zero());
        $fee = (array) ($order->fee_breakdown ?? []);
        /** @var array{cut: Money, amount: Money, lines: list<array<string, mixed>>} $extra */
        $extra = $plan['extra'];
        if ($extra['lines'] !== []) {
            $fee['extra_delivery'] = ['amount' => $extra['amount']->toDecimal(), 'lines' => $extra['lines']]; // units shown on the order follow what is left, even when the fee stays
        }
        if ($extra['cut']->isPositive()) {
            // The bulky-item fees that went with the units that are gone: off the parcel's delivery fee and off the stored breakdown.
            $fee['delivery'] = collect($fee['delivery'] ?? [])->map(fn ($d) => ($d['branch_id'] ?? null) === $f->branch_id ? array_merge($d, [
                'fee' => Money::of((string) ($d['fee'] ?? '0'))->minus($extra['cut'])->max(Money::zero())->toDecimal(),
                'extra' => Money::of((string) ($d['extra'] ?? '0'))->minus($extra['cut'])->max(Money::zero())->toDecimal()]) : $d)->all();
            $f->forceFill(['delivery_fee' => Money::of($f->delivery_fee)->minus($extra['cut'])->max(Money::zero())->toDecimal()]);
        }
        if ($plan['fulfillment_empty']) {
            $fee['delivery'] = collect($fee['delivery'] ?? [])->map(fn ($d) => ($d['branch_id'] ?? null) === $f->branch_id ? array_merge($d, ['fee' => '0.00']) : $d)->all();
            $f->forceFill(['delivery_fee' => '0.00']);
        }
        if (array_key_exists('delivery_discount', $fee) || $after['delivery_discount']->isPositive()) {
            $fee['delivery_discount'] = $after['delivery_discount']->toDecimal();
        }
        $tax = (array) ($order->tax_snapshot ?? []);
        $tax['total'] = $totals['tax_total']->toDecimal();
        $f->forceFill(['subtotal' => $liveSubtotal->toDecimal()])->save();
        $order->forceFill([
            'subtotal' => $totals['subtotal']->toDecimal(), 'discount_total' => $totals['discount_total']->toDecimal(), 'tax_total' => $totals['tax_total']->toDecimal(),
            'delivery_fee_total' => $after['delivery_fee']->toDecimal(), 'loyalty_discount' => $totals['loyalty_discount']->toDecimal(), 'loyalty_points_redeemed' => $plan['loyalty_points'],
            'grand_total' => $totals['grand_total']->toDecimal(), 'fee_breakdown' => $fee, 'tax_snapshot' => $tax, 'adjustment_count' => $number,
        ])->save();
    }

    /** The coupon lines on the order (and their usage rows) follow the smaller discount: each keeps its share, to the dirham. */
    private function rescaleDiscounts(Order $order, Money $itemDiscount, Money $deliveryDiscount): void
    {
        $coupons = OrderCoupon::where('order_id', $order->id)->get();
        $items = $coupons->reject(fn (OrderCoupon $c) => $c->free_delivery);
        $shares = $itemDiscount->allocate($items->mapWithKeys(fn (OrderCoupon $c) => [$c->id => max(0, Money::of($c->discount_amount)->minor)])->all());
        foreach ($coupons as $c) {
            $new = $c->free_delivery ? $deliveryDiscount : $shares[$c->id];
            if (! Money::of($c->discount_amount)->equals($new)) {
                $c->forceFill(['discount_amount' => $new->toDecimal()])->save();
                if ($c->promotion_id !== null) { // a deal's line has no promotion usage row
                    CouponUsage::where('order_id', $order->id)->where('promotion_id', $c->promotion_id)->update(['discount_amount' => $new->toDecimal()]);
                }
            }
        }
    }

    /** Cash on delivery / pay at pickup: the amount due follows the new total (the payment, and what the driver is told to collect). */
    private function syncPayment(Order $order, Money $grand): void
    {
        if (! in_array($order->payment_method, ['cod', 'pay_at_pickup'], true)) {
            return;
        }
        $open = Payment::where('order_id', $order->id)->whereIn('status', ['pending', 'processing'])->lockForUpdate()->get();
        foreach ($open as $p) {
            $p->forceFill(['amount' => $grand->max(Money::of($p->captured_amount))->toDecimal()])->save();
        }
        if ($order->payment_method === 'cod') {
            Delivery::whereIn('order_fulfillment_id', $order->fulfillments()->select('id'))->whereNotIn('status', [DS::Delivered->value, DS::Returned->value, DS::Cancelled->value])
                ->update(['cod_amount' => $grand->toDecimal()]);
        }
    }

    /** Every unit of the parcel is gone: the branch's part is cancelled (stock and slot released, status derived again), its open delivery with it. */
    private function cancelEmptyFulfillment(OrderFulfillment $f, User $actor, int $number): void
    {
        foreach ($f->deliveries()->whereIn('status', [DS::Pending->value, DS::Assigned->value, DS::Accepted->value])->get() as $d) {
            app(DeliveryService::class)->transition($d, DS::Cancelled, $actor, 'system', 'order items changed', system: true);
        }
        $this->orders->transition($f->fresh(), FS::Cancelled, $actor, 'order_adjustment', ['revision' => $number], system: true);
    }

    /**
     * In-app (in the same transaction) and push (queued after the commit, in the language of each phone). A failure never undoes the adjustment:
     * the staff member is told through `customer_notified`.
     */
    private function notifyCustomer(Order $order, OrderRevision $revision, ?Money $refund, bool $cancelled, Money $newTotal): bool
    {
        $user = $order->user;
        if ($user === null || ! $user->isActive()) {
            return false;
        }
        try {
            return DB::transaction(function () use ($order, $revision, $refund, $cancelled, $newTotal, $user) {
                $locales = (array) config('platform.supported_locales');
                $for = fn (string $locale) => [
                    'order_number' => $order->number, 'name' => Str::before($order->customer_name, ' '), 'changes' => AdjustmentText::lines($revision->lines, $locale),
                    'reason' => AdjustmentText::reason($revision->reason_code, $locale), 'total' => $newTotal->format($locale),
                    'outcome' => $cancelled ? __('adjustments.outcome.cancelled', [], $locale)
                        : __('adjustments.outcome.total', ['total' => $newTotal->format($locale)], $locale).($refund ? __('adjustments.outcome.refund', ['amount' => $refund->format($locale)], $locale) : ''),
                ];
                $mine = $user->preferredLocale();
                $sent = $this->notifications->notify($user, 'order.items_changed', $for($mine) + [
                    'deep_link' => 'albahar://orders/'.$order->id, 'reference' => $order->id, 'by_locale' => collect($locales)->mapWithKeys(fn ($l) => [$l => $for($l)])->all(),
                ]);

                return $sent !== [];
            });
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    // ─── Presenting ─────────────────────────────────────────────────────────────────────────────────────────────────

    /** The contract's `data` object. */
    private function present(array $plan, ?OrderRevision $revision, bool $notified = false, ?int $invoiceRevision = null): array
    {
        $m = fn (Money $v) => $v->jsonSerialize();
        $totals = fn (array $t) => ['subtotal' => $m($t['subtotal']), 'discount_total' => $m($t['discount_total']), 'tax_total' => $m($t['tax_total']), 'grand_total' => $m($t['grand_total'])];

        return [
            'applied' => $revision !== null, 'preview' => $revision === null, 'replayed' => false,
            'revision' => ['id' => $revision?->id, 'number' => $plan['number']],
            'lines' => collect($plan['changes'])->map(function (array $c) {
                /** @var OrderItem $i */
                $i = $c['item'];
                $sub = $c['sub'];

                return ['order_item_id' => $i->id, 'action' => $c['action'], 'name' => $i->name(), 'variant_name' => $i->variantName(), 'from_quantity' => $c['from'], 'to_quantity' => $c['to'],
                    'replacement' => $sub === null ? null : ['variant_id' => $sub['variant']->id, 'name' => (string) $sub['variant']->product->trans('name'), 'variant_name' => $sub['variant']->displayName(),
                        'quantity' => $sub['quantity'], 'unit_price' => $sub['unit']->jsonSerialize()]];
            })->values()->all(),
            'before' => $totals($plan['before']), 'after' => $totals($plan['after_totals']), 'difference' => $m($plan['difference']),
            'refund' => $plan['refund'] ? ['amount' => $m($plan['refund']['amount']), 'needs_approval' => true] : null,
            'fulfillment_cancelled' => $plan['fulfillment_empty'], 'order_cancelled' => $plan['order_cancelled'], 'customer_notified' => $notified,
            'invoice_revision' => $invoiceRevision, 'loyalty_points_returned' => $plan['loyalty_returned'],
        ];
    }

    /** @param  array<string, Money>  $t @return array<string, string> */
    private function totalsArray(array $t): array
    {
        return array_map(fn (Money $v) => $v->toDecimal(), $t);
    }

    /** @return array<string, mixed> */
    private function replacementSnapshot(array $sub, OrderItem $new): array
    {
        /** @var ProductVariant $v */
        $v = $sub['variant'];

        return ['variant_id' => $v->id, 'sku' => $v->sku, 'name_en' => $new->name_en, 'name_ar' => $new->name_ar, 'variant_name_en' => $new->variant_name_en,
            'variant_name_ar' => $new->variant_name_ar, 'quantity' => $sub['quantity'], 'unit_price' => $sub['unit']->toDecimal()];
    }

    // ─── Rules ──────────────────────────────────────────────────────────────────────────────────────────────────────

    /** `status` while the parcel cannot be adjusted any more (or yet); null when it can. */
    public function blockedReason(OrderFulfillment $f, Order $order): ?string
    {
        $open = in_array($f->status, [FS::Accepted, FS::Preparing], true)
            && ! in_array($order->status, [OS::PendingPayment, OS::CancellationRequested], true) && ! $order->status->isTerminal();

        return $open ? null : 'status';
    }

    private function authorize(User $actor, OrderFulfillment $f): void
    {
        if (! $this->access->allows($actor, 'orders.edit', $f->branch_id)) {
            throw DomainException::make('branch_forbidden', 403);
        }
    }

    /** Shape only (the API's Form Request checks the same before it gets here); the rules about the order itself are in plan(). */
    private function assertShape(array $changes, string $reasonCode): void
    {
        $ok = $changes !== [] && count($changes) <= self::MAX_CHANGES && in_array($reasonCode, OrderRevision::REASONS, true);
        foreach ($changes as $c) {
            $ok = $ok && is_array($c) && is_string($c['order_item_id'] ?? null) && Str::isUuid($c['order_item_id'])
                && in_array($c['action'] ?? null, ['reduce', 'remove', 'replace'], true)
                && (($c['action'] ?? null) !== 'reduce' || (isset($c['new_quantity']) && is_int($c['new_quantity'])))
                && (($c['action'] ?? null) !== 'replace' || (is_string($c['variant_id'] ?? null) && Str::isUuid($c['variant_id']) && isset($c['quantity']) && is_int($c['quantity'])));
        }
        if (! $ok) {
            throw DomainException::make('validation_failed');
        }
    }
}
