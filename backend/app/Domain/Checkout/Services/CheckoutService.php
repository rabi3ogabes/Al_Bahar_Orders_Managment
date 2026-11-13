<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Services\SlotService;
use App\Domain\Checkout\Data\Quote;
use App\Domain\Checkout\Data\QuoteLine;
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
    public function __construct(
        private readonly QuoteService $quotes,
        private readonly ReservationService $reservations,
        private readonly SlotService $slots,
        private readonly PromotionEngine $promotions,
        private readonly LoyaltyService $loyalty,
        private readonly PaymentService $payments,
        private readonly OrderService $orders,
        private readonly AuditLogger $audit,
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

        $result = DB::transaction(function () use ($user, $draft, $staff, $options, $quoteHash, $idempotencyKey, $notes) {
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

            $quote = $this->quotes->quote($user, $cart, $options);
            if (! $quote->canPlace()) {
                throw new DomainException('checkout_invalid', $quote->errors[0]['message'] ?? __('checkout.checkout_invalid'), 422, ['quote' => $quote->toArray()]);
            }
            if (! hash_equals($quote->hash, $quoteHash)) {
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

            // Promotions & loyalty.
            foreach ($quote->discounts as $d) {
                OrderCoupon::create(['order_id' => $order->id, 'coupon_id' => $d->coupon?->id, 'promotion_id' => $d->promotion->id, 'code' => $d->coupon?->code,
                    'label_en' => $d->promotion->name_en, 'label_ar' => $d->promotion->name_ar, 'discount_amount' => $d->amount->plus($d->deliveryDiscount)->toDecimal(),
                    'free_delivery' => $d->freeDelivery]);
            }
            $this->promotions->recordUsage($quote->discounts, $order->id, $user);
            if ($quote->loyaltyPoints > 0) {
                $this->loyalty->redeem($user, $quote->loyaltyPoints, $order->id);
            }

            $payment = $this->payments->createForOrder($order, 'order:'.$order->id.':1');
            $this->orders->history($order, null, 'order', null, $order->status->value, $staff ?? $user, 'placed',
                ['payment_method' => $order->payment_method] + ($staff ? ['on_behalf_of' => $user->id] : []));
            $cart->update(['status' => 'converted', 'converted_order_id' => $order->id]);
            $this->audit->log('order.placed', $order, [], ['number' => $order->number, 'total' => $order->grand_total, 'method' => $order->payment_method,
                'branches' => collect($quote->plans)->map(fn ($p) => $p->branch->code)->all()]
                + ($staff ? ['placed_by_staff_id' => $staff->id, 'customer_id' => $user->id] : []), actor: $staff ?? $user);

            return ['order' => $order, 'payment' => $payment, 'replayed' => false];
        });

        DB::afterCommit(fn () => event(new OrderPlaced($result['order']->id)));

        return $result;
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
            'service_fee_total' => $quote->serviceFee->plus($quote->codFee)->toDecimal(),
            'tax_total' => $quote->taxTotal()->toDecimal(),
            'loyalty_discount' => $quote->loyaltyDiscount->toDecimal(),
            'loyalty_points_redeemed' => $quote->loyaltyPoints,
            'grand_total' => $quote->grandTotal()->toDecimal(),
            'customer_name' => $user->name,
            'customer_phone' => $user->phone,
            'customer_email' => $user->email,
            'address_snapshot' => $quote->address?->snapshot() ?? ($quote->fulfillmentType === 'pickup' ? ['pickup_branch_id' => $plan0->branch->id, 'pickup_branch' => $plan0->branch->name_en] : null),
            'fee_breakdown' => [
                'delivery' => array_map(fn ($p) => ['branch_id' => $p->branch->id, 'fee' => $p->deliveryFee->toDecimal(), 'express' => $p->express], $quote->plans),
                'delivery_discount' => $quote->deliveryDiscountTotal()->toDecimal(),
                'service_fee' => $quote->serviceFee->toDecimal(),
                'cod_fee' => $quote->codFee->toDecimal(),
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
