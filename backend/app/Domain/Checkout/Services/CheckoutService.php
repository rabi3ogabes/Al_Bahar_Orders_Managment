<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Services\SlotService;
use App\Domain\Checkout\Data\Quote;
use App\Domain\Checkout\Data\QuoteLine;
use App\Domain\Deals\Services\DealEngine;
use App\Domain\Deals\Services\DealService;
use App\Domain\Fulfillment\Models\PickupReservation;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Services\ReservationService;
use App\Domain\Marketing\Models\OrderCoupon;
use App\Domain\Marketing\Services\LoyaltyService;
use App\Domain\Marketing\Services\PromotionEngine;
use App\Domain\Orders\Enums\FulfillmentStatus;
use App\Domain\Orders\Enums\OrderStatus;
use App\Domain\Orders\Events\OrderPlaced;
use App\Domain\Orders\Models\Cart;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Services\OrderService;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Services\PaymentService;
use App\Support\DocumentNumber;
use App\Support\DomainException;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Order placement — one database transaction, all-or-nothing:
 *   1. idempotency (advisory lock on user+key; replay returns the original order)
 *   2. re-quote from current data and compare with the hash the customer confirmed (409 quote_changed if different)
 *   3. allocate branches (inside the quote)            4. lock inventory rows & reserve every line
 *   5. parent order  6. item snapshots  7. branch fulfillments  8. slots, coupons, loyalty  9. payment record  10. commit
 */
class CheckoutService
{
    /** How many times an order is tried when the database reports a deadlock. */
    private const DEADLOCK_ATTEMPTS = 3;

    public function __construct(
        private readonly QuoteService $quotes,
        private readonly ReservationService $reservations,
        private readonly SlotService $slots,
        private readonly PromotionEngine $promotions,
        private readonly LoyaltyService $loyalty,
        private readonly PaymentService $payments,
        private readonly OrderService $orders,
        private readonly AuditLogger $audit,
        private readonly DealService $deals,
        private readonly DealEngine $dealEngine,
    ) {}

    /** @return array{order: Order, payment: Payment, replayed: bool} */
    public function place(User $user, array $options, string $quoteHash, string $idempotencyKey, ?string $notes = null): array
    {
        return $this->placeCart($user, null, $options, $quoteHash, $idempotencyKey, $notes);
    }

    /**
     * Place the customer's active cart, or — when a staff member acts on the customer's behalf — that staff draft
     * (source 'admin', remembered in orders.placed_by_staff_id, status history and the audit log).
     *
     * @return array{order: Order, payment: Payment, replayed: bool}
     */
    public function placeCart(User $user, ?Cart $draft, array $options, string $quoteHash, string $idempotencyKey, ?string $notes = null, ?User $staff = null): array
    {
        if ($draft && (! $staff || $draft->staff_id !== $staff->id || $draft->user_id !== $user->id)) {
            throw DomainException::make('cart_empty', 422); // a draft can only be placed by its owner, for its customer
        }
        if ($existing = $this->existing($user, $idempotencyKey)) {
            return $existing;
        }

        try {
            $result = $this->placeInTransaction($user, $draft, $staff, $options, $quoteHash, $idempotencyKey, $notes);
        } catch (DomainException $e) {
            throw $this->withFreshQuote($e, $user, $draft, $options);
        }

        DB::afterCommit(fn () => event(new OrderPlaced($result['order']->id)));

        return $result;
    }

    /**
     * A deal that stops covering the order at the very last step (the claim) is answered like any other deal change: `409 deal_changed` carrying the
     * fresh quote, built after the failed attempt was rolled back, so the customer sees the new total and confirms again.
     */
    private function withFreshQuote(DomainException $e, User $user, ?Cart $draft, array $options): DomainException
    {
        if ($e->errorCode !== 'deal_changed' || isset($e->context['quote'])) {
            return $e;
        }
        $cart = $draft ?? Cart::where('user_id', $user->id)->where('status', 'active')->first();

        return $cart ? new DomainException('deal_changed', $e->getMessage(), 409, $e->context + ['quote' => $this->quotes->quote($user, $cart, $options)->toArray()]) : $e;
    }

    /**
     * The order transaction, tried again (up to three times) when PostgreSQL picks it as the victim of a deadlock. That is safe because a failed attempt is
     * rolled back whole and an attempt starts by checking the idempotency key again; nothing outside the database is touched inside it (the order event goes
     * out after the commit). Two orders that want the same rows in the same order cannot deadlock; this is for the day another path gets it wrong.
     *
     * @return array{order: Order, payment: Payment, replayed: bool}
     */
    private function placeInTransaction(User $user, ?Cart $draft, ?User $staff, array $options, string $quoteHash, string $idempotencyKey, ?string $notes): array
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->placeOnce($user, $draft, $staff, $options, $quoteHash, $idempotencyKey, $notes);
            } catch (QueryException|DeadlockException $e) { // (inside an outer transaction Laravel reports a deadlock as a DeadlockException)
                if ($attempt >= self::DEADLOCK_ATTEMPTS || ! in_array((string) $e->getCode(), ['40P01', '40001'], true) && ! str_contains(strtolower($e->getMessage()), 'deadlock detected')) {
                    throw $e;
                }
                usleep(random_int(20_000, 80_000));
            }
        }
    }

    /** @return array{order: Order, payment: Payment, replayed: bool} */
    private function placeOnce(User $user, ?Cart $draft, ?User $staff, array $options, string $quoteHash, string $idempotencyKey, ?string $notes): array
    {
        return DB::transaction(function () use ($user, $draft, $staff, $options, $quoteHash, $idempotencyKey, $notes) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['checkout:'.$user->id.':'.$idempotencyKey]);
            if ($existing = $this->existing($user, $idempotencyKey)) {
                return $existing;
            }
            $cart = $draft
                ? Cart::whereKey($draft->id)->where('status', Cart::STAFF_DRAFT)->lockForUpdate()->first()
                : Cart::where('user_id', $user->id)->where('status', 'active')->lockForUpdate()->first();
            if (! $cart) {
                throw DomainException::make('cart_empty', 422);
            }
            // Deals: lock the deal rows of the cart's products BEFORE quoting, so the quote below is read from rows nobody else can change
            // (two orders for the last unit take turns). A staff-made order gets no deal prices and takes no lock.
            if (! $staff) {
                $this->deals->lockForCart($cart);
            }

            $quote = $this->quotes->quote($user, $cart, $options);
            if (! $quote->canPlace()) {
                throw new DomainException('checkout_invalid', $quote->errors[0]['message'] ?? __('checkout.checkout_invalid'), 422, ['quote' => $quote->toArray()]);
            }
            if (! hash_equals($quote->hash, $quoteHash)) {
                // The customer confirmed a quote with deal prices that this quote no longer gives: say so ("a deal changed"), not just "prices changed".
                if ($this->dealEngine->changedSince($quoteHash, $quote)) {
                    $line = $quote->lines()->first(fn (QuoteLine $l) => $l->deal !== null);
                    throw new DomainException('deal_changed', __('errors.deal_changed'), 409, array_filter(['deal_id' => $line?->deal?->deal->id, 'product_id' => $line?->productId()]) + ['quote' => $quote->toArray()]);
                }
                throw new DomainException('quote_changed', __('checkout.quote_changed'), 409, ['quote' => $quote->toArray()]);
            }

            $online = $quote->paymentMethod === 'sadad';
            $dueAt = $online ? now()->addMinutes((int) config('platform.payments.online_payment_window_minutes')) : null;
            $order = $this->createOrder($user, $quote, $idempotencyKey, $notes, $online, $dueAt, $staff);

            // Fulfillments + item snapshots.
            $lines = [];
            foreach ($quote->plans as $i => $plan) {
                $f = OrderFulfillment::create([
                    'order_id' => $order->id, 'branch_id' => $plan->branch->id, 'number' => $order->number.'-'.($i + 1), 'type' => $plan->type,
                    'status' => FulfillmentStatus::Pending, 'delivery_zone_id' => $plan->zoneConfig?->delivery_zone_id,
                    'subtotal' => $plan->subtotal()->toDecimal(), 'delivery_fee' => $plan->deliveryFee->toDecimal(),
                    'delivery_slot_id' => $plan->slotId, 'slot_date' => $plan->slotDate, 'is_express' => $plan->express,
                    'allocation_reason' => $plan->allocationReason,
                ]);
                foreach ($plan->lines as $line) {
                    $item = $this->snapshotItem($order, $f, $line, $quote->taxRate);
                    $lines[] = ['branch_id' => $plan->branch->id, 'variant_id' => $line->variant->id, 'quantity' => $line->quantity,
                        'reference_type' => 'order_item', 'reference_id' => $item->id];
                }
                if ($plan->slotId && $plan->slotDate) {
                    $this->slots->book($plan->slotId, $plan->slotDate, $plan->branch->id, $plan->type);
                }
                if ($plan->type === 'pickup') {
                    PickupReservation::create(['order_fulfillment_id' => $f->id, 'branch_id' => $plan->branch->id, 'delivery_slot_id' => $plan->slotId,
                        'slot_date' => $plan->slotDate, 'status' => 'pending']);
                }
            }

            // Lock inventory rows (deterministic order) and reserve everything — or roll back the whole order.
            $this->reservations->reserve($lines, $dueAt, $user->id);

            // Deals: take the units the quote promised (the rows are already locked); a unit that is gone is `deal_changed`, never a silent new price.
            $this->deals->claim($order, $user, $quote->lines()->all());

            // Promotions, deal prices & loyalty.
            foreach ($quote->discounts as $d) {
                OrderCoupon::create(['order_id' => $order->id, 'coupon_id' => $d->coupon?->id, 'promotion_id' => $d->promotion?->id, 'deal_id' => $d->deal?->id, 'code' => $d->coupon?->code,
                    'label_en' => $d->deal?->name_en ?? $d->promotion?->name_en, 'label_ar' => $d->deal?->name_ar ?? $d->promotion?->name_ar,
                    'discount_amount' => $d->amount->plus($d->deliveryDiscount)->toDecimal(), 'free_delivery' => $d->freeDelivery]);
            }
            $this->promotions->recordUsage(array_values(array_filter($quote->discounts, fn ($d) => $d->promotion !== null)), $order->id, $user);
            if ($quote->loyaltyPoints > 0) {
                $this->loyalty->redeem($user, $quote->loyaltyPoints, $order->id);
            }

            // The number the customer typed for the driver becomes their saved one, so the next order starts filled in.
            // (Never from staff placing an order for them: that must not change the customer's profile.)
            if ($quote->alternatePhoneGiven && ! $staff && $quote->alternatePhone !== $user->alternate_phone) {
                $user->forceFill(['alternate_phone' => $quote->alternatePhone])->save();
            }

            $payment = $this->payments->createForOrder($order, 'order:'.$order->id.':1');
            $this->orders->history($order, null, 'order', null, $order->status->value, $staff ?? $user, 'placed',
                ['payment_method' => $order->payment_method] + ($staff ? ['on_behalf_of' => $user->id] : []));
            $cart->update(['status' => 'converted', 'converted_order_id' => $order->id]);
            // A staff draft made from the customer's own abandoned cart ("Place order for customer"): that cart is now ordered too,
            // so it stops being abandoned, the reminders stop and the customer does not find the same items waiting to be bought again.
            $sourceId = $draft ? ($cart->staff_options[StaffOrderService::SOURCE_KEY] ?? null) : null;
            if ($sourceId) {
                Cart::whereKey($sourceId)->where('user_id', $user->id)->where('status', 'active')->update(['status' => 'converted', 'converted_order_id' => $order->id]);
            }
            $this->audit->log('order.placed', $order, [], ['number' => $order->number, 'total' => $order->grand_total, 'method' => $order->payment_method,
                'branches' => collect($quote->plans)->map(fn ($p) => $p->branch->code)->all()]
                + ($staff ? ['placed_by_staff_id' => $staff->id, 'customer_id' => $user->id] : []), actor: $staff ?? $user);

            return ['order' => $order, 'payment' => $payment, 'replayed' => false];
        });
    }

    private function createOrder(User $user, Quote $quote, string $key, ?string $notes, bool $online, $dueAt, ?User $staff = null): Order
    {
        $plan0 = $quote->plans[0];

        return Order::create([
            'number' => DocumentNumber::order(),
            'user_id' => $user->id,
            'source' => $staff ? 'admin' : 'app',
            'placed_by_staff_id' => $staff?->id,
            'status' => $online ? OrderStatus::PendingPayment : OrderStatus::Confirmed,
            'payment_status' => PaymentStatus::Pending,
            'payment_method' => $quote->paymentMethod,
            'fulfillment_type' => $quote->fulfillmentType,
            'currency' => 'QAR',
            'subtotal' => $quote->subtotal()->toDecimal(),
            'discount_total' => $quote->discountTotal()->toDecimal(),
            'delivery_fee_total' => $quote->deliveryFeeTotal()->toDecimal(),
            'service_fee_total' => $quote->serviceFee->toDecimal(),
            'tax_total' => $quote->taxTotal()->toDecimal(),
            'loyalty_discount' => $quote->loyaltyDiscount->toDecimal(),
            'loyalty_points_redeemed' => $quote->loyaltyPoints,
            'grand_total' => $quote->grandTotal()->toDecimal(),
            'customer_name' => $user->name,
            'customer_phone' => $user->phone,
            'alternate_phone' => $quote->alternatePhone,
            'customer_email' => $user->email,
            'address_snapshot' => $quote->address?->snapshot() ?? ($quote->fulfillmentType === 'pickup' ? ['pickup_branch_id' => $plan0->branch->id, 'pickup_branch' => $plan0->branch->name_en] : null),
            // `delivery[].fee` is each fulfillment's whole delivery fee (zone fee, oversized, express AND its products' extra fees; `extra` is that last part).
            // `extra_delivery` keeps what each product added, so the order page, the invoice and an order adjustment never read the live catalog.
            // Orders placed before cash on delivery became free still carry their own `cod_fee` here; new orders have none.
            'fee_breakdown' => [
                'delivery' => array_map(fn ($p) => ['branch_id' => $p->branch->id, 'fee' => $p->deliveryFee->toDecimal(), 'express' => $p->express, 'extra' => $p->extraDeliveryFee()->toDecimal()], $quote->plans),
                'delivery_discount' => $quote->deliveryDiscountTotal()->toDecimal(),
                'service_fee' => $quote->serviceFee->toDecimal(),
                'extra_delivery' => ['amount' => $quote->extraDeliveryFeeTotal()->toDecimal(), 'lines' => array_map(fn ($e) => $e->toStored(), $quote->deliveryExtras())],
            ],
            'tax_snapshot' => ['rate_percent' => $quote->taxRate, 'total' => $quote->taxTotal()->toDecimal(), 'inclusive' => false],
            'locale' => $user->preferredLocale(),
            'customer_notes' => $notes,
            'idempotency_key' => $key,
            'quote_hash' => $quote->hash,
            'placed_at' => now(),
            'confirmed_at' => $online ? null : now(),
            'payment_due_at' => $dueAt,
        ]);
    }

    /** Immutable line snapshot: names in both languages, SKU, attributes, image, prices, discount and tax. */
    private function snapshotItem(Order $order, OrderFulfillment $f, QuoteLine $line, string $taxRate): OrderItem
    {
        $v = $line->variant;
        $p = $v->product;
        $attributes = $v->attributeValues->map(fn ($av) => ['attribute' => $av->attribute?->code, 'value_en' => $av->value_en, 'value_ar' => $av->value_ar])->values()->all();
        $subtotal = $line->subtotal();
        $deal = $line->deal !== null && $line->deal->units > 0 ? $line->deal : null;

        return OrderItem::create([
            'order_id' => $order->id, 'order_fulfillment_id' => $f->id, 'branch_id' => $line->branch->id,
            'product_id' => $p->id, 'product_variant_id' => $v->id, 'sku' => $v->sku,
            'name_en' => $p->trans('name', 'en'), 'name_ar' => $p->trans('name', 'ar') ?? $p->trans('name', 'en'),
            'variant_name_en' => $v->displayName('en'), 'variant_name_ar' => $v->displayName('ar'),
            'variant_attributes' => $attributes ?: null,
            'image_path' => ($v->images->first() ?? $p->images->first())?->path,
            'unit_price' => $line->price->regular->toDecimal(),
            'sale_price' => $line->price->sale?->toDecimal(),
            'unit_final_price' => $line->price->final()->toDecimal(),
            'quantity' => $line->quantity,
            'line_subtotal' => $subtotal->toDecimal(),
            'discount_amount' => $line->discount->toDecimal(),
            'tax_rate' => $taxRate,
            'tax_amount' => $line->tax->toDecimal(),
            'line_total' => $subtotal->minus($line->discount)->plus($line->tax)->toDecimal(),
            'price_source' => $line->price->source,
            // What the deal did to this line, as placed (invoices and revised invoices never read the live deal).
            'deal_id' => $deal?->deal->id, 'deal_name_en' => $deal?->deal->name_en, 'deal_name_ar' => $deal?->deal->name_ar,
            'deal_units' => $deal?->units ?? 0, 'deal_unit_savings' => $deal?->unitSavings->toDecimal(),
        ]);
    }

    private function existing(User $user, string $key): ?array
    {
        $order = Order::where('user_id', $user->id)->where('idempotency_key', $key)->first();
        if (! $order) {
            return null;
        }

        return ['order' => $order, 'payment' => Payment::where('order_id', $order->id)->latest('attempt')->first(), 'replayed' => true];
    }
}
