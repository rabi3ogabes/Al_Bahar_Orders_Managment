<?php

namespace App\Domain\Auctions\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Auctions\Models\Auction;
use App\Domain\Auctions\Models\AuctionBidder;
use App\Domain\Auctions\Models\AuctionDeposit;
use App\Domain\Auctions\Models\AuctionLot;
use App\Domain\Auctions\Models\AuctionProduct;
use App\Domain\Auctions\Models\AuctionWinner;
use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Services\DeliveryZoneResolver;
use App\Domain\Fulfillment\Models\PickupReservation;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Services\ReservationService;
use App\Domain\Orders\Enums\FulfillmentStatus;
use App\Domain\Orders\Enums\OrderStatus;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\UserAddress;
use App\Domain\Orders\Services\OrderService;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Services\PaymentService;
use App\Support\DocumentNumber;
use App\Support\DomainException;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Customer-side auction workflows and auction administration. */
class AuctionService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{bidder: AuctionBidder, deposit: ?AuctionDeposit, payment: ?Payment} */
    public function register(User $user, Auction $auction): array
    {
        if (! in_array($auction->status, ['scheduled', 'live'], true)) {
            throw DomainException::make('bid_not_open', 409);
        }
        if ($auction->requires_verified_phone && ! $user->phone_verified_at) {
            throw DomainException::make('bid_phone_not_verified', 409);
        }

        return DB::transaction(function () use ($user, $auction) {
            $needsDeposit = Money::of($auction->deposit_amount)->isPositive();
            $bidder = AuctionBidder::where('auction_id', $auction->id)->where('user_id', $user->id)->first();
            if (! $bidder) {
                $paddle = (string) (AuctionBidder::where('auction_id', $auction->id)->count() + 101);
                $bidder = AuctionBidder::create(['auction_id' => $auction->id, 'user_id' => $user->id, 'paddle_number' => $paddle,
                    'status' => $needsDeposit ? 'pending_deposit' : 'approved', 'terms_accepted_at' => now()]);
            }
            $deposit = null;
            $payment = null;
            if ($needsDeposit && $bidder->status === 'pending_deposit') {
                $deposit = AuctionDeposit::firstOrCreate(['auction_id' => $auction->id, 'user_id' => $user->id], ['amount' => $auction->deposit_amount, 'status' => 'pending']);
                $payment = Payment::where('payable_type', 'auction_deposit')->where('payable_id', $deposit->id)->whereIn('status', ['pending', 'processing'])->first()
                    ?? Payment::create(['payable_type' => 'auction_deposit', 'payable_id' => $deposit->id, 'user_id' => $user->id, 'method' => 'sadad', 'provider' => 'sadad',
                        'status' => PaymentStatus::Pending, 'amount' => $deposit->amount, 'idempotency_key' => 'deposit:'.$deposit->id.':'.Str::random(6), 'expires_at' => now()->addMinutes(30)]);
                $deposit->update(['payment_id' => $payment->id]);
            }

            return ['bidder' => $bidder, 'deposit' => $deposit, 'payment' => $payment];
        });
    }

    /** Called when a deposit payment is captured. */
    public function depositPaid(Payment $payment): void
    {
        $deposit = AuctionDeposit::find($payment->payable_id);
        if (! $deposit || $deposit->status !== 'pending') {
            return;
        }
        $deposit->update(['status' => 'paid', 'paid_at' => now()]);
        AuctionBidder::where('auction_id', $deposit->auction_id)->where('user_id', $deposit->user_id)->where('status', 'pending_deposit')->update(['status' => 'approved']);
    }

    public function watch(User $user, AuctionLot $lot, bool $on): void
    {
        $on
            ? DB::table('auction_watchlists')->insertOrIgnore(['user_id' => $user->id, 'auction_lot_id' => $lot->id, 'created_at' => now()])
            : DB::table('auction_watchlists')->where('user_id', $user->id)->where('auction_lot_id', $lot->id)->delete();
    }

    /**
     * Winner checkout: creates an auction order at the winning price. Auction COD is disabled unless the auction allows it.
     * The lot's stock hold is converted into an order-item reservation inside the same transaction.
     *
     * @return array{order: Order, payment: Payment}
     */
    public function checkoutWin(User $user, AuctionWinner $winner, array $o): array
    {
        if ($winner->user_id !== $user->id) {
            throw DomainException::make('not_found', 404);
        }

        return DB::transaction(function () use ($user, $winner, $o) {
            $winner = AuctionWinner::whereKey($winner->id)->lockForUpdate()->firstOrFail();
            if ($winner->order_id) {
                $order = Order::findOrFail($winner->order_id);

                return ['order' => $order, 'payment' => Payment::where('order_id', $order->id)->latest('attempt')->first()];
            }
            if ($winner->status !== 'pending_payment' || $winner->payment_due_at->isPast()) {
                throw DomainException::make('auction_win_not_payable', 409);
            }
            $lot = AuctionLot::findOrFail($winner->auction_lot_id);
            $auction = Auction::findOrFail($lot->auction_id);
            $type = $o['fulfillment_type'];
            $method = $o['payment_method'];
            if (($type === 'delivery' && ! $auction->delivery_allowed) || ($type === 'pickup' && ! $auction->pickup_allowed)
                || ($method === 'cod' && ! $auction->cod_allowed) || ($method === 'pay_at_pickup' && $type !== 'pickup') || ($method === 'cod' && $type !== 'delivery')) {
                throw DomainException::make('payment_method_unavailable');
            }
            $product = AuctionProduct::where('auction_lot_id', $lot->id)->with('variant.product')->first();
            $branch = Branch::findOrFail($o['branch_id'] ?? $product?->branch_id ?? $auction->branches()->value('branches.id'));

            $fee = Money::zero();
            $address = null;
            if ($type === 'delivery') {
                $address = UserAddress::where('user_id', $user->id)->findOrFail($o['address_id'] ?? null);
                $zone = app(DeliveryZoneResolver::class)->resolve($address->delivery_zone_id, $address->zone_number, $address->latitude, $address->longitude);
                $cfg = $zone ? app(DeliveryZoneResolver::class)->servingBranches($zone)->firstWhere('branch_id', $branch->id) : null;
                if (! $cfg) {
                    throw DomainException::make('delivery_unavailable_area');
                }
                $fee = Money::of($cfg->delivery_fee)->plus($product?->variant->product->is_oversized ? Money::of($cfg->oversized_surcharge) : Money::zero());
            }
            $price = Money::of($winner->amount)->times(1);
            $total = $price->plus($fee);
            $online = $method === 'sadad';
            $order = Order::create([
                'number' => DocumentNumber::order(), 'user_id' => $user->id, 'source' => 'auction', 'status' => $online ? OrderStatus::PendingPayment : OrderStatus::Confirmed,
                'payment_status' => PaymentStatus::Pending, 'payment_method' => $method, 'fulfillment_type' => $type, 'currency' => 'QAR',
                'subtotal' => $price->toDecimal(), 'delivery_fee_total' => $fee->toDecimal(), 'grand_total' => $total->toDecimal(),
                'customer_name' => $user->name, 'customer_phone' => $user->phone, 'customer_email' => $user->email,
                'address_snapshot' => $address?->snapshot() ?? ['pickup_branch_id' => $branch->id, 'pickup_branch' => $branch->name_en],
                'locale' => $user->preferredLocale(), 'idempotency_key' => 'auction-win:'.$winner->id, 'auction_winner_id' => $winner->id,
                'placed_at' => now(), 'confirmed_at' => $online ? null : now(), 'payment_due_at' => $winner->payment_due_at,
            ]);
            $f = OrderFulfillment::create(['order_id' => $order->id, 'branch_id' => $branch->id, 'number' => $order->number.'-1', 'type' => $type,
                'status' => FulfillmentStatus::Pending, 'subtotal' => $price->toDecimal(), 'delivery_fee' => $fee->toDecimal(), 'allocation_reason' => 'auction']);
            $item = OrderItem::create([
                'order_id' => $order->id, 'order_fulfillment_id' => $f->id, 'branch_id' => $branch->id,
                'product_id' => $product?->variant->product_id, 'product_variant_id' => $product?->product_variant_id,
                'sku' => $product?->variant->sku ?? 'LOT-'.$lot->lot_number, 'name_en' => $lot->title_en, 'name_ar' => $lot->title_ar,
                'image_path' => $lot->image_path, 'unit_price' => $winner->amount, 'unit_final_price' => $winner->amount, 'quantity' => 1,
                'line_subtotal' => $winner->amount, 'line_total' => $winner->amount, 'price_source' => 'auction',
            ]);
            if ($product) {
                $reservations = app(ReservationService::class);
                if ($held = $reservations->forReference('auction_product', $product->id)) {
                    $reservations->release($held, 'converted_to_order');
                }
                $reservations->reserve([['branch_id' => $branch->id, 'variant_id' => $product->product_variant_id, 'quantity' => 1, 'reference_type' => 'order_item', 'reference_id' => $item->id]]);
            }
            if ($type === 'pickup') {
                PickupReservation::create(['order_fulfillment_id' => $f->id, 'branch_id' => $branch->id, 'status' => 'pending']);
            }
            $payment = app(PaymentService::class)->createForOrder($order, 'auction:'.$winner->id);
            app(OrderService::class)->history($order, null, 'order', null, $order->status->value, $user, 'auction_win', ['lot' => $lot->lot_number]);
            $winner->update(['order_id' => $order->id, 'confirmed_at' => now()]);

            return ['order' => $order, 'payment' => $payment];
        });
    }

    /** Admin: create/update an auction with translations, branches and lots (draft until published). */
    public function save(User $actor, array $data, ?Auction $auction = null): Auction
    {
        return DB::transaction(function () use ($actor, $data, $auction) {
            $fields = collect($data)->only(['type', 'starts_at', 'ends_at', 'requires_registration', 'requires_verified_phone', 'deposit_amount', 'winner_payment_hours',
                'cod_allowed', 'pickup_allowed', 'delivery_allowed', 'extension_window_seconds', 'extension_seconds', 'max_extensions', 'unpaid_winner_policy',
                'show_bidder_names', 'cover_image_path', 'video_url'])->all();
            if ($auction) {
                if (! in_array($auction->status, ['draft', 'scheduled'], true)) {
                    throw DomainException::make('invalid_state_transition', 409, ['from' => $auction->status]);
                }
                $auction->update($fields);
            } else {
                $auction = Auction::create($fields + ['reference' => DocumentNumber::make('AUC'), 'status' => 'draft', 'created_by' => $actor->id]);
            }
            foreach ($data['translations'] ?? [] as $locale => $t) {
                $auction->translations()->updateOrCreate(['locale' => $locale], ['title' => $t['title'], 'description' => $t['description'] ?? null, 'terms' => $t['terms'] ?? null]);
            }
            if (isset($data['branch_ids'])) {
                $auction->branches()->sync($data['branch_ids']);
            }
            $this->audit->log($auction->wasRecentlyCreated ? 'auction.created' : 'auction.updated', $auction, [], $fields, actor: $actor);

            return $auction->fresh(['translations', 'lots']);
        });
    }

    public function addLot(User $actor, Auction $auction, array $l): AuctionLot
    {
        return DB::transaction(function () use ($actor, $auction, $l) {
            $lot = $auction->lots()->create([
                'lot_number' => (int) ($l['lot_number'] ?? ($auction->lots()->max('lot_number') + 1)), 'title_en' => $l['title_en'], 'title_ar' => $l['title_ar'],
                'description_en' => $l['description_en'] ?? null, 'description_ar' => $l['description_ar'] ?? null, 'image_path' => $l['image_path'] ?? null,
                'quantity' => $l['quantity'] ?? 1, 'starting_price' => $l['starting_price'], 'reserve_price' => $l['reserve_price'] ?? null,
                'min_increment' => $l['min_increment'], 'buy_now_price' => $l['buy_now_price'] ?? null,
                'starts_at' => $l['starts_at'] ?? $auction->starts_at, 'ends_at' => $l['ends_at'] ?? $auction->ends_at, 'sort_order' => $l['sort_order'] ?? 0,
            ]);
            if (! empty($l['product_variant_id'])) {
                $variant = \App\Domain\Catalog\Models\ProductVariant::findOrFail($l['product_variant_id']);
                AuctionProduct::create(['auction_lot_id' => $lot->id, 'product_id' => $variant->product_id, 'product_variant_id' => $variant->id,
                    'branch_id' => $l['branch_id'], 'quantity' => $l['quantity'] ?? 1]);
            }
            $this->audit->log('auction.lot_added', $lot, [], ['lot_number' => $lot->lot_number, 'starting_price' => $lot->starting_price], actor: $actor);

            return $lot;
        });
    }

    public function publish(User $actor, Auction $auction): Auction
    {
        if ($auction->status !== 'draft' || ! $auction->lots()->exists() || ! $auction->translations()->exists()) {
            throw DomainException::make('auction_not_publishable', 422);
        }
        if ($auction->type === 'scheduled' && $auction->lots()->where(fn ($q) => $q->whereNull('starts_at')->orWhereNull('ends_at'))->exists()) {
            throw DomainException::make('auction_not_publishable', 422);
        }
        $auction->update(['status' => 'scheduled', 'published_at' => now()]);
        $this->audit->log('auction.published', $auction, ['status' => 'draft'], ['status' => 'scheduled'], actor: $actor);

        return $auction;
    }

    public function cancel(User $actor, Auction $auction, string $reason): Auction
    {
        foreach ($auction->lots()->whereIn('status', ['pending', 'open', 'paused'])->get() as $lot) {
            app(LotService::class)->cancel($lot, $actor, $reason);
        }
        $auction->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
        $this->audit->log('auction.cancelled', $auction, [], ['reason' => $reason], actor: $actor);

        return $auction;
    }
}
