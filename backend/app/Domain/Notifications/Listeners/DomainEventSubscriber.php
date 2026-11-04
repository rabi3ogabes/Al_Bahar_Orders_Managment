<?php

namespace App\Domain\Notifications\Listeners;

use App\Domain\Auctions\Events\AuctionOutbid;
use App\Domain\Auctions\Events\AuctionWon;
use App\Domain\Auctions\Models\AuctionBid;
use App\Domain\Auctions\Models\AuctionWinner;
use App\Domain\Catalog\Models\StockAlert;
use App\Domain\Identity\Events\CustomerRegistered;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Events\BackInStock;
use App\Domain\Marketing\Services\LoyaltyService;
use App\Domain\Marketing\Services\ReferralService;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Orders\Events\OrderPlaced;
use App\Domain\Orders\Events\OrderStatusChanged;
use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Events\PaymentStatusChanged;
use App\Domain\Payments\Models\Payment;
use App\Domain\Pricing\Events\PriceChanged;
use App\Support\Money;
use Illuminate\Events\Dispatcher;
use Throwable;

/**
 * Reacts to committed domain events: customer notifications, loyalty earning, referral rewards and stock/price alerts.
 * Failures here never break the originating business transaction (it has already committed) — they are reported.
 */
class DomainEventSubscriber
{
    public function __construct(private readonly NotificationService $notify) {}

    public function subscribe(Dispatcher $events): array
    {
        return [
            OrderPlaced::class => 'orderPlaced',
            OrderStatusChanged::class => 'orderStatusChanged',
            PaymentStatusChanged::class => 'paymentStatusChanged',
            AuctionWon::class => 'auctionWon',
            AuctionOutbid::class => 'auctionOutbid',
            BackInStock::class => 'backInStock',
            PriceChanged::class => 'priceChanged',
            CustomerRegistered::class => 'customerRegistered',
        ];
    }

    public function orderPlaced(OrderPlaced $e): void
    {
        $this->safely(function () use ($e) {
            $order = Order::with('user')->find($e->orderId);
            if ($order?->user) {
                $this->notify->notify($order->user, 'order.created', $this->orderData($order));
            }
        });
    }

    public function orderStatusChanged(OrderStatusChanged $e): void
    {
        $this->safely(function () use ($e) {
            $order = Order::with('user')->find($e->orderId);
            if (! $order?->user) {
                return;
            }
            $event = match ($e->to) {
                'confirmed' => $e->from === 'pending_payment' ? 'order.confirmed' : null,
                'processing' => 'order.processing', 'ready_for_pickup' => 'order.ready_for_pickup', 'out_for_delivery' => 'order.out_for_delivery',
                'completed', 'partially_cancelled' => 'order.completed', 'cancelled' => 'order.cancelled', default => null,
            };
            if ($event) {
                $this->notify->notify($order->user, $event, $this->orderData($order));
            }
            if (in_array($e->to, ['completed', 'partially_cancelled'], true)) {
                // Loyalty: earn on the net amount actually paid for goods (excluding delivery), once per order.
                $net = Money::of($order->paid_total ?: $order->grand_total)->minus(Money::of($order->refunded_total))->minus(Money::of($order->delivery_fee_total));
                app(LoyaltyService::class)->earnForOrder($order->user, $net, $order->id);
                app(ReferralService::class)->qualify($order);
            }
        });
    }

    public function paymentStatusChanged(PaymentStatusChanged $e): void
    {
        $this->safely(function () use ($e) {
            $p = Payment::with('order.user')->find($e->paymentId);
            if (! $p?->order?->user || $p->method !== 'sadad') {
                return;
            }
            $event = match ($e->to) { 'paid' => 'payment.succeeded', 'failed' => 'payment.failed', default => null };
            if ($event) {
                $this->notify->notify($p->order->user, $event, $this->orderData($p->order));
            }
            if (in_array($e->to, ['refunded', 'partially_refunded'], true)) {
                $this->notify->notify($p->order->user, 'refund.processed', $this->orderData($p->order) + ['amount' => Money::of($p->refunded_amount)->format($p->order->user->preferredLocale())]);
            }
        });
    }

    public function auctionWon(AuctionWon $e): void
    {
        $this->safely(function () use ($e) {
            $w = AuctionWinner::with(['user', 'lot'])->find($e->winnerId);
            if (! $w) {
                return;
            }
            $data = ['lot' => $w->lot->localized('title', $w->user->preferredLocale()), 'amount' => Money::of($w->amount)->format($w->user->preferredLocale()),
                'deadline' => \App\Support\BusinessTime::display($w->payment_due_at), 'deep_link' => 'albahar://auctions/lots/'.$w->auction_lot_id];
            $this->notify->notify($w->user, 'auction.won', $data);
            if ($w->rank === 1) {
                $losers = AuctionBid::where('auction_lot_id', $w->auction_lot_id)->where('status', 'accepted')->where('user_id', '!=', $w->user_id)->distinct()->pluck('user_id');
                User::whereIn('id', $losers)->get()->each(fn ($u) => $this->notify->notify($u, 'auction.lost', ['lot' => $w->lot->localized('title', $u->preferredLocale()), 'deep_link' => $data['deep_link']]));
            }
        });
    }

    public function auctionOutbid(AuctionOutbid $e): void
    {
        $this->safely(function () use ($e) {
            $u = User::find($e->userId);
            if ($u) {
                $this->notify->notify($u, 'auction.outbid', ['lot' => $e->lotTitle, 'amount' => $e->amount, 'deep_link' => 'albahar://auctions/lots/'.$e->lotId]);
            }
        });
    }

    public function backInStock(BackInStock $e): void
    {
        $this->safely(function () use ($e) {
            StockAlert::with('user')->where('product_variant_id', $e->variantId)->where('type', 'back_in_stock')->whereNull('notified_at')
                ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $e->branchId))->limit(500)->get()
                ->each(function (StockAlert $a) {
                    $p = $a->variant?->product;
                    $this->notify->notify($a->user, 'stock.back_in_stock', ['product' => $p?->trans('name', $a->user->preferredLocale()), 'deep_link' => 'albahar://products/'.$p?->id]);
                    $a->update(['notified_at' => now()]);
                });
        });
    }

    public function priceChanged(PriceChanged $e): void
    {
        $this->safely(function () use ($e) {
            $newFinal = $e->newSale ?? $e->newPrice;
            if ($newFinal === null) {
                return;
            }
            StockAlert::with('user', 'variant.product')->where('type', 'price_drop')->whereNull('notified_at')
                ->whereHas('variant', fn ($q) => $q->where('product_id', $e->productId))
                ->when($e->variantId, fn ($q) => $q->where('product_variant_id', $e->variantId))
                ->get()->filter(fn ($a) => $a->reference_price === null || Money::of($newFinal)->lessThan(Money::of($a->reference_price)))
                ->each(function (StockAlert $a) use ($newFinal) {
                    $this->notify->notify($a->user, 'stock.price_drop', ['product' => $a->variant->product->trans('name', $a->user->preferredLocale()),
                        'price' => Money::of($newFinal)->format($a->user->preferredLocale()), 'deep_link' => 'albahar://products/'.$a->variant->product_id]);
                    $a->update(['notified_at' => now()]);
                });
        });
    }

    public function customerRegistered(CustomerRegistered $e): void
    {
        $this->safely(fn () => $e->referralCode ? app(ReferralService::class)->attach($e->user, $e->referralCode, $e->deviceId) : null);
    }

    private function orderData(Order $o): array
    {
        $locale = $o->locale ?: 'en';

        return ['order_number' => $o->number, 'total' => Money::of($o->grand_total)->format($locale), 'name' => \Illuminate\Support\Str::before($o->customer_name, ' '),
            'deep_link' => 'albahar://orders/'.$o->id, 'reference' => $o->id];
    }

    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
