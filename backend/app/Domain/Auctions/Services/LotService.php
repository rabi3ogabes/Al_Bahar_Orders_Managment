<?php

namespace App\Domain\Auctions\Services;

use App\Domain\Auctions\Events\AuctionWon;
use App\Domain\Auctions\Models\Auction;
use App\Domain\Auctions\Models\AuctionAutoBid;
use App\Domain\Auctions\Models\AuctionBid;
use App\Domain\Auctions\Models\AuctionDeposit;
use App\Domain\Auctions\Models\AuctionLot;
use App\Domain\Auctions\Models\AuctionProduct;
use App\Domain\Auctions\Models\AuctionWinner;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\Services\ReservationService;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\OrderService;
use App\Support\DomainException;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Lot lifecycle: pending → open ⇄ paused → closed → sold | unsold (or cancelled).
 *
 *  - Scheduled auctions: tick() opens lots at starts_at and closes them at (possibly extended) ends_at, by server time.
 *  - Live auctions: the auctioneer opens/pauses/resumes/closes lots; every action is audited.
 *  - Stock for catalog-linked lots is RESERVED when the lot opens (cannot be sold elsewhere) and released if unsold.
 *  - Closing is atomic under the lot lock; the winner is reproducible from the accepted-bid ledger
 *    (highest amount, then lowest bid_number).
 */
class LotService
{
    public function __construct(private readonly BidEngine $engine, private readonly ReservationService $reservations, private readonly AuditLogger $audit) {}

    public function open(AuctionLot $lot, ?User $actor = null, ?int $durationSeconds = null): AuctionLot
    {
        $events = [];
        $lot = DB::transaction(function () use ($lot, $actor, $durationSeconds, &$events) {
            $lot = AuctionLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();
            if ($lot->status !== 'pending') {
                throw DomainException::make('invalid_state_transition', 409, ['from' => $lot->status]);
            }
            $now = CarbonImmutable::now();
            $this->reserveStock($lot);
            $endsAt = $durationSeconds ? $now->addSeconds($durationSeconds) : ($lot->ends_at ?? $now->addHour());
            $lot->forceFill(['status' => 'open', 'starts_at' => $lot->starts_at && $lot->starts_at < $now ? $lot->starts_at : $now,
                'ends_at' => $endsAt, 'original_ends_at' => $endsAt])->save();
            $auction = Auction::whereKey($lot->auction_id)->lockForUpdate()->first();
            if ($auction->status === 'scheduled') {
                $auction->update(['status' => 'live']);
            }
            $events[] = $this->engine->event($lot, 'opened', ['ends_at' => $endsAt->toIso8601String()], $actor?->id);
            if ($actor) {
                $this->audit->log('auction.lot_opened', $lot, [], ['ends_at' => $endsAt->toIso8601String()], actor: $actor);
            }

            return $lot;
        });
        $this->engine->broadcast($lot, $events);

        return $lot;
    }

    public function pause(AuctionLot $lot, User $actor, string $reason): AuctionLot
    {
        return $this->mutate($lot, $actor, 'paused', function (AuctionLot $l) {
            if ($l->status !== 'open') {
                throw DomainException::make('invalid_state_transition', 409, ['from' => $l->status]);
            }
            $l->forceFill(['status' => 'paused', 'paused_at' => now(), 'paused_remaining_seconds' => max(0, (int) now()->diffInSeconds($l->ends_at, false))])->save();
        }, ['reason' => $reason]);
    }

    public function resume(AuctionLot $lot, User $actor): AuctionLot
    {
        return $this->mutate($lot, $actor, 'resumed', function (AuctionLot $l) {
            if ($l->status !== 'paused') {
                throw DomainException::make('invalid_state_transition', 409, ['from' => $l->status]);
            }
            // The clock does not run while paused.
            $l->forceFill(['status' => 'open', 'ends_at' => now()->addSeconds(max(30, (int) $l->paused_remaining_seconds)), 'paused_at' => null, 'paused_remaining_seconds' => null])->save();
        });
    }

    /** Close (normal end, buy-now, or auctioneer hammer). Idempotent: closing a closed lot is a no-op. */
    public function close(AuctionLot $lot, string $reason = 'ended', array &$events = [], ?User $actor = null): AuctionLot
    {
        $outer = $events;
        $lot = DB::transaction(function () use ($lot, $reason, &$events, $actor) {
            $lot = AuctionLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();
            if (! in_array($lot->status, ['open', 'paused'], true)) {
                return $lot;
            }
            $auction = Auction::findOrFail($lot->auction_id);
            $winningBid = $this->winningBid($lot);
            $reserveOk = $lot->reserve_price === null || ($winningBid && Money::of($winningBid->amount)->greaterThanOrEqual(Money::of($lot->reserve_price)));
            $sold = $winningBid && $reserveOk;
            $lot->forceFill(['status' => $sold ? 'sold' : 'unsold', 'closed_at' => now()])->save();
            AuctionAutoBid::where('auction_lot_id', $lot->id)->where('status', 'active')->update(['status' => 'lost']);

            if ($sold) {
                $winner = AuctionWinner::create(['auction_lot_id' => $lot->id, 'user_id' => $winningBid->user_id, 'auction_bid_id' => $winningBid->id,
                    'amount' => $winningBid->amount, 'rank' => 1, 'status' => 'pending_payment', 'payment_due_at' => now()->addHours($auction->winner_payment_hours)]);
                AuctionAutoBid::where('auction_lot_id', $lot->id)->where('user_id', $winningBid->user_id)->update(['status' => 'won']);
                DB::afterCommit(fn () => event(new AuctionWon($winner->id, $winner->user_id, $lot->id)));
            } else {
                $this->releaseStock($lot);
            }
            $events[] = $this->engine->event($lot, 'closed', ['result' => $sold ? 'sold' : 'unsold', 'reason' => $reason,
                'final_price' => $winningBid?->amount, 'reserve_met' => $lot->reserve_price === null ? null : $reserveOk], $actor?->id);
            if ($actor) {
                $this->audit->log('auction.lot_closed', $lot, [], ['result' => $lot->status, 'reason' => $reason], actor: $actor);
            }
            $this->maybeCloseAuction($auction);

            return $lot;
        });
        if ($outer === [] && $events !== []) {
            $this->engine->broadcast($lot, $events);
        }

        return $lot;
    }

    public function cancel(AuctionLot $lot, User $actor, string $reason): AuctionLot
    {
        return $this->mutate($lot, $actor, 'cancelled', function (AuctionLot $l) {
            if (in_array($l->status, ['sold', 'unsold', 'cancelled'], true)) {
                throw DomainException::make('invalid_state_transition', 409, ['from' => $l->status]);
            }
            $l->forceFill(['status' => 'cancelled', 'closed_at' => now()])->save();
            AuctionAutoBid::where('auction_lot_id', $l->id)->where('status', 'active')->update(['status' => 'cancelled']);
            $this->releaseStock($l);
        }, ['reason' => $reason]);
    }

    /** Admin override (e.g. retract a fraudulent bid): requires a reason; recomputes the lot from the remaining ledger. */
    public function retractBid(AuctionBid $bid, User $actor, string $reason): AuctionLot
    {
        return $this->mutate($bid->lot, $actor, 'override', function (AuctionLot $l) use ($bid) {
            if (! in_array($l->status, ['open', 'paused'], true) || $bid->status !== 'accepted') {
                throw DomainException::make('invalid_state_transition', 409, ['from' => $l->status]);
            }
            $bid->update(['status' => 'retracted']);
            $top = $this->winningBid($l);
            $l->forceFill(['current_price' => $top?->amount, 'current_bid_id' => $top?->id, 'current_leader_id' => $top?->user_id, 'bid_count' => max(0, $l->bid_count - 1)])->save();
        }, ['retracted_bid' => $bid->bid_number, 'reason' => $reason]);
    }

    /** Scheduler: open due lots, close ended lots, default unpaid winners. Safe to run every few seconds. */
    public function tick(): array
    {
        $now = now();
        $opened = $closed = 0;
        AuctionLot::where('status', 'pending')->whereNotNull('starts_at')->where('starts_at', '<=', $now)
            ->whereHas('auction', fn ($q) => $q->where('type', 'scheduled')->whereIn('status', ['scheduled', 'live']))
            ->limit(100)->get()->each(function ($lot) use (&$opened) {
                try {
                    $this->open($lot);
                    $opened++;
                } catch (InsufficientStock $e) {
                    report($e);
                }
            });
        AuctionLot::where('status', 'open')->where('ends_at', '<=', $now)->limit(200)->get()->each(function ($lot) use (&$closed) {
            $this->close($lot);
            $closed++;
        });

        return ['opened' => $opened, 'closed' => $closed, 'defaulted' => $this->defaultUnpaidWinners()];
    }

    /** Apply the auction's published unpaid-winner policy once the payment deadline passes. */
    public function defaultUnpaidWinners(): int
    {
        $n = 0;
        AuctionWinner::whereIn('status', ['pending_payment', 'pending_confirmation'])->where('payment_due_at', '<', now())->limit(100)->get()
            ->each(function (AuctionWinner $w) use (&$n) {
                DB::transaction(function () use ($w, &$n) {
                    $w = AuctionWinner::whereKey($w->id)->lockForUpdate()->first();
                    if (! in_array($w->status, ['pending_payment', 'pending_confirmation'], true)) {
                        return;
                    }
                    $lot = AuctionLot::whereKey($w->auction_lot_id)->lockForUpdate()->first();
                    $auction = Auction::find($lot->auction_id);
                    $w->update(['status' => 'defaulted', 'defaulted_at' => now()]);
                    if ($w->order_id) {
                        $order = Order::find($w->order_id);
                        if ($order && ! $order->payment_status->isSettled()) {
                            app(OrderService::class)->cancelOrder($order, null, 'auction_payment_deadline', null, system: true);
                        }
                    }
                    // Deposit outcome follows published terms; flagged for finance/legal review rather than auto-forfeited.
                    AuctionDeposit::where('auction_id', $auction->id)->where('user_id', $w->user_id)->where('status', 'paid')
                        ->update(['resolution_note' => 'Winner defaulted — review per auction terms']);
                    $this->audit->log('auction.winner_defaulted', $w, [], ['policy' => $auction->unpaid_winner_policy]);

                    if ($auction->unpaid_winner_policy === 'offer_next_bidder') {
                        $this->offerNextBidder($lot, $w, $auction);
                    } elseif ($auction->unpaid_winner_policy === 'relist') {
                        // Back to pending so the auction team can schedule it again; stock is re-reserved when it reopens.
                        $this->releaseStock($lot);
                        $lot->forceFill(['status' => 'pending', 'starts_at' => null, 'ends_at' => null, 'current_price' => null,
                            'current_bid_id' => null, 'current_leader_id' => null, 'bid_count' => 0])->save();
                    } else {
                        $this->releaseStock($lot);
                        $lot->forceFill(['status' => 'unsold'])->save();
                    }
                    $n++;
                });
            });

        return $n;
    }

    public function winningBid(AuctionLot $lot): ?AuctionBid
    {
        return AuctionBid::where('auction_lot_id', $lot->id)->where('status', 'accepted')->orderByDesc('amount')->orderBy('bid_number')->first();
    }

    private function offerNextBidder(AuctionLot $lot, AuctionWinner $previous, Auction $auction): void
    {
        $excluded = AuctionWinner::where('auction_lot_id', $lot->id)->pluck('user_id');
        $next = AuctionBid::where('auction_lot_id', $lot->id)->where('status', 'accepted')->whereNotIn('user_id', $excluded)
            ->orderByDesc('amount')->orderBy('bid_number')->first();
        $reserveOk = $next && ($lot->reserve_price === null || Money::of($next->amount)->greaterThanOrEqual(Money::of($lot->reserve_price)));
        if (! $reserveOk) {
            $lot->forceFill(['status' => 'unsold'])->save();
            $this->releaseStock($lot);

            return;
        }
        $winner = AuctionWinner::create(['auction_lot_id' => $lot->id, 'user_id' => $next->user_id, 'auction_bid_id' => $next->id, 'amount' => $next->amount,
            'rank' => $previous->rank + 1, 'status' => 'pending_payment', 'payment_due_at' => now()->addHours($auction->winner_payment_hours)]);
        DB::afterCommit(fn () => event(new AuctionWon($winner->id, $winner->user_id, $lot->id)));
    }

    private function reserveStock(AuctionLot $lot): void
    {
        $lines = AuctionProduct::where('auction_lot_id', $lot->id)->get()->map(fn ($p) => ['branch_id' => $p->branch_id, 'variant_id' => $p->product_variant_id,
            'quantity' => $p->quantity, 'reference_type' => 'auction_product', 'reference_id' => $p->id])->all();
        if ($lines) {
            $this->reservations->reserve($lines);
        }
    }

    public function releaseStock(AuctionLot $lot): void
    {
        foreach (AuctionProduct::where('auction_lot_id', $lot->id)->get() as $p) {
            if ($r = $this->reservations->forReference('auction_product', $p->id)) {
                $this->reservations->release($r, 'auction_lot_'.$lot->status);
            }
        }
    }

    private function maybeCloseAuction(Auction $auction): void
    {
        if (! AuctionLot::where('auction_id', $auction->id)->whereIn('status', ['pending', 'open', 'paused'])->exists() && in_array($auction->status, ['live', 'scheduled', 'paused'], true)) {
            $auction->update(['status' => 'closed']);
        }
    }

    private function mutate(AuctionLot $lot, User $actor, string $eventType, callable $change, array $meta = []): AuctionLot
    {
        if (! app(AccessControl::class)->allows($actor, $eventType === 'override' ? 'auctions.override' : 'auctions.edit')) {
            throw DomainException::make('branch_forbidden', 403);
        }
        $events = [];
        $lot = DB::transaction(function () use ($lot, $actor, $eventType, $change, $meta, &$events) {
            $l = AuctionLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();
            $before = $l->only(['status', 'ends_at', 'current_price']);
            $change($l);
            $events[] = $this->engine->event($l, $eventType, $meta + ['status' => $l->status, 'ends_at' => $l->ends_at?->toIso8601String()], $actor->id);
            $this->audit->log('auction.lot_'.$eventType, $l, $before, $l->only(['status', 'ends_at', 'current_price']), $meta, actor: $actor);

            return $l;
        });
        $this->engine->broadcast($lot, $events);

        return $lot;
    }
}
