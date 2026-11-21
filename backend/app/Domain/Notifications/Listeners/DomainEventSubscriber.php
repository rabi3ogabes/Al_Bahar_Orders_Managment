<?php

namespace App\Domain\Notifications\Listeners;

use App\Domain\Auctions\Events\AuctionOutbid;
use App\Domain\Auctions\Events\AuctionWon;
use App\Domain\Auctions\Models\AuctionBid;
use App\Domain\Auctions\Models\AuctionWinner;
use App\Domain\Catalog\Jobs\ProcessStockAlertsJob;
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
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Str;
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
                // Never more than the order's current total: an item adjustment lowers it before its refund is paid out.
                $paid = Money::of($order->paid_total ?: $order->grand_total)->minus(Money::of($order->refunded_total));
                $net = $paid->min(Money::of($order->grand_total))->minus(Money::of($order->delivery_fee_total));
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
            $event = match ($e->to) {
                'paid' => 'payment.succeeded', 'failed' => 'payment.failed', default => null
            };
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
                'deadline' => BusinessTime::display($w->payment_due_at), 'deep_link' => 'albahar://auctions/lots/'.$w->auction_lot_id];
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

    /**
     * Stock came back: queue a look at the customers who asked (docs/guides/ALERTS_AFTER_DELIVERY_AND_ROOMS.md). The job waits a moment and checks the
     * stock as it is then, so a variant that sold out again is not announced; it is queued after the stock movement commits.
     */
    public function backInStock(BackInStock $e): void
    {
        $this->safely(fn () => ProcessStockAlertsJob::dispatch('back_in_stock', $e->variantId)->delay(now()->addSeconds(ProcessStockAlertsJob::DELAY_SECONDS))->afterCommit());
    }

    /** A price was edited: queue a look at the price alerts of that product (the job compares today's price with what each customer last saw). */
    public function priceChanged(PriceChanged $e): void
    {
        $this->safely(fn () => ProcessStockAlertsJob::dispatch('price_drop', $e->productId)->delay(now()->addSeconds(ProcessStockAlertsJob::DELAY_SECONDS))->afterCommit());
    }

    public function customerRegistered(CustomerRegistered $e): void
    {
        $this->safely(fn () => $e->referralCode ? app(ReferralService::class)->attach($e->user, $e->referralCode, $e->deviceId) : null);
    }

    private function orderData(Order $o): array
    {
        $locale = $o->locale ?: 'en';

        return ['order_number' => $o->number, 'total' => Money::of($o->grand_total)->format($locale), 'name' => Str::before($o->customer_name, ' '),
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
