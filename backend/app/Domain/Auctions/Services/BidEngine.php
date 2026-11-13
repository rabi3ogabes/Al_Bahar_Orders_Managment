<?php

namespace App\Domain\Auctions\Services;

use App\Domain\Auctions\Events\AuctionOutbid;
use App\Domain\Auctions\Events\LotUpdated;
use App\Domain\Auctions\Models\Auction;
use App\Domain\Auctions\Models\AuctionAutoBid;
use App\Domain\Auctions\Models\AuctionBid;
use App\Domain\Auctions\Models\AuctionBidder;
use App\Domain\Auctions\Models\AuctionDeposit;
use App\Domain\Auctions\Models\AuctionExtension;
use App\Domain\Auctions\Models\AuctionLot;
use App\Domain\Auctions\Models\AuctionLotEvent;
use App\Domain\Identity\Models\User;
use App\Support\DomainException;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Server-authoritative bidding. PostgreSQL is the ledger; Reverb only distributes committed state.
 *
 * Every bid: (1) row-lock the lot, (2) validate against SERVER time & state, (3) allocate the next bid_number,
 * (4) insert the bid and update lot state, (5) apply anti-sniping, (6) resolve proxy (auto) bids, (7) append
 * sequenced lot events, (8) commit — and only then broadcast. Concurrent bids serialise on the lot lock, so ordering
 * is deterministic (lock acquisition order) and each accepted bid has a unique, monotonically increasing number.
 *
 * PROXY TIE POLICY: when two maximums are equal, the EARLIEST maximum (auto_bid.set_at, then id) wins; a later
 * equal maximum can never displace it. Another bidder's maximum is never exposed.
 */
class BidEngine
{
    /** @return array{bid: AuctionBid, lot: AuctionLot, replayed: bool} */
    public function placeBid(User $user, string $lotId, Money $amount, string $idempotencyKey, string $type = 'manual', ?string $ip = null): array
    {
        if ($existing = AuctionBid::where('user_id', $user->id)->where('idempotency_key', $idempotencyKey)->first()) {
            $this->assertNotRejected($existing);

            return ['bid' => $existing, 'lot' => $existing->lot()->first(), 'replayed' => true];
        }

        $replayed = false;
        [$bid, $lot, $error, $events] = DB::transaction(function () use ($user, $lotId, $amount, $idempotencyKey, $type, $ip, &$replayed) {
            $lot = AuctionLot::whereKey($lotId)->lockForUpdate()->firstOrFail();
            $auction = Auction::findOrFail($lot->auction_id);
            $now = CarbonImmutable::now();

            // Duplicate submission that raced the first check.
            if ($existing = AuctionBid::where('user_id', $user->id)->where('idempotency_key', $idempotencyKey)->first()) {
                $replayed = true;
                if ($existing->status === 'rejected') {
                    return [$existing, $lot, $existing->rejection_reason, []];
                }

                return [$existing, $lot, null, []];
            }

            $reason = $this->rejection($user, $auction, $lot, $amount, $now, $type);
            if ($reason !== null) {
                $rejected = AuctionBid::create(['auction_lot_id' => $lot->id, 'user_id' => $user->id, 'bid_number' => null, 'amount' => $amount->toDecimal(),
                    'type' => $type, 'status' => 'rejected', 'rejection_reason' => $reason, 'idempotency_key' => $idempotencyKey, 'ip' => $ip, 'placed_at' => $now]);

                return [$rejected, $lot, $reason, []];
            }

            $events = [];
            $bid = $this->accept($lot, $user, $amount, $type, $idempotencyKey, $ip, $now, $events);
            if ($lot->buy_now_price !== null && $amount->greaterThanOrEqual(Money::of($lot->buy_now_price))) {
                app(LotService::class)->close($lot, 'buy_now', $events);
            } else {
                $this->resolveProxies($lot, $now, $events);
            }

            return [$bid, $lot, null, $events];
        });

        if ($error !== null) {
            throw DomainException::make('bid_'.$error, $error === 'outbid_min' ? 422 : 409, ['min_next_bid' => $lot->minNextBid()->format()]);
        }
        $this->broadcast($lot, $events);

        return ['bid' => $bid, 'lot' => $lot->fresh(), 'replayed' => $replayed];
    }

    /** Retrying a bid that was rejected must report the same rejection again, never a 200 "replayed" success. */
    private function assertNotRejected(AuctionBid $bid): void
    {
        if ($bid->status === 'rejected') {
            $lot = $bid->lot()->first();
            throw DomainException::make('bid_'.$bid->rejection_reason, $bid->rejection_reason === 'outbid_min' ? 422 : 409, ['min_next_bid' => $lot->minNextBid()->format()]);
        }
    }

    /** Set (or replace) a maximum the server bids on the customer's behalf. */
    public function setAutoBid(User $user, string $lotId, Money $max): AuctionAutoBid
    {
        [$auto, $lot, $events] = DB::transaction(function () use ($user, $lotId, $max) {
            $lot = AuctionLot::whereKey($lotId)->lockForUpdate()->firstOrFail();
            $auction = Auction::findOrFail($lot->auction_id);
            $now = CarbonImmutable::now();
            $reason = $this->rejection($user, $auction, $lot, $max, $now, 'auto');
            if ($reason !== null) {
                throw DomainException::make('bid_'.$reason, 409, ['min_next_bid' => $lot->minNextBid()->format()]);
            }
            $current = AuctionAutoBid::where('auction_lot_id', $lot->id)->where('user_id', $user->id)->where('status', 'active')->lockForUpdate()->first();
            if ($current && Money::of($max->toDecimal())->lessThan(Money::of($current->max_amount))) {
                throw DomainException::make('bid_auto_cannot_lower', 409);
            }
            if ($current) {
                // Raising keeps the original priority time (fairness: a raise never loses an earlier tie).
                $current->update(['max_amount' => $max->toDecimal()]);
                $auto = $current;
            } else {
                $auto = AuctionAutoBid::create(['auction_lot_id' => $lot->id, 'user_id' => $user->id, 'max_amount' => $max->toDecimal(), 'status' => 'active', 'set_at' => $now]);
            }
            $events = [];
            $this->resolveProxies($lot, $now, $events);

            return [$auto, $lot, $events];
        });
        $this->broadcast($lot, $events);

        return $auto;
    }

    public function cancelAutoBid(User $user, string $lotId): void
    {
        AuctionAutoBid::where('auction_lot_id', $lotId)->where('user_id', $user->id)->where('status', 'active')->update(['status' => 'cancelled']);
    }

    /** Validation of a bid attempt; returns a machine reason or null. */
    private function rejection(User $user, Auction $auction, AuctionLot $lot, Money $amount, CarbonImmutable $now, string $type): ?string
    {
        if (! in_array($auction->status, ['live', 'scheduled'], true) || ! $lot->isOpenAt($now)) {
            return $lot->ends_at && $now >= $lot->ends_at ? 'closed' : 'not_open';
        }
        if ($auction->requires_verified_phone && ! $user->phone_verified_at) {
            return 'phone_not_verified';
        }
        if ($auction->requires_registration || (float) $auction->deposit_amount > 0) {
            $bidder = AuctionBidder::where('auction_id', $auction->id)->where('user_id', $user->id)->first();
            if (! $bidder) {
                return 'not_registered';
            }
            if ($bidder->status === 'blocked') {
                return 'blocked';
            }
            if ($bidder->status !== 'approved') {
                return 'deposit_required';
            }
        }
        if ((float) $auction->deposit_amount > 0 && ! AuctionDeposit::where('auction_id', $auction->id)->where('user_id', $user->id)->whereIn('status', ['paid', 'applied'])->exists()) {
            return 'deposit_required';
        }
        if ($amount->lessThan($lot->minNextBid())) {
            return 'outbid_min';
        }
        if ($type === 'manual' && $lot->current_leader_id === $user->id) {
            return 'already_leading';
        }

        return null;
    }

    private function accept(AuctionLot $lot, User|string $user, Money $amount, string $type, ?string $key, ?string $ip, CarbonImmutable $now, array &$events): AuctionBid
    {
        $userId = $user instanceof User ? $user->id : $user;
        $number = $lot->last_bid_number + 1;
        $bid = AuctionBid::create(['auction_lot_id' => $lot->id, 'user_id' => $userId, 'bid_number' => $number, 'amount' => $amount->toDecimal(),
            'type' => $type, 'status' => 'accepted', 'idempotency_key' => $key, 'ip' => $ip, 'placed_at' => $now]);
        $previousLeader = $lot->current_leader_id;
        $lot->forceFill(['current_price' => $amount->toDecimal(), 'current_bid_id' => $bid->id, 'current_leader_id' => $userId,
            'bid_count' => $lot->bid_count + 1, 'last_bid_number' => $number])->save();
        $events[] = $this->event($lot, 'bid_accepted', ['bid_number' => $number, 'amount' => $amount->toDecimal(), 'type' => $type, 'previous_leader' => $previousLeader !== $userId ? $previousLeader : null]);
        if ($previousLeader && $previousLeader !== $userId) {
            $title = $lot->title_en;
            DB::afterCommit(fn () => event(new AuctionOutbid($previousLeader, $lot->id, $title, $amount->format())));
        }
        $this->maybeExtend($lot, $bid, $now, $events);

        return $bid;
    }

    /** Anti-sniping: a valid bid inside the closing window pushes the end time out (bounded by max_extensions). */
    private function maybeExtend(AuctionLot $lot, AuctionBid $bid, CarbonImmutable $now, array &$events): void
    {
        $auction = Auction::find($lot->auction_id);
        $window = (int) $auction->extension_window_seconds;
        if ($window <= 0 || ! $lot->ends_at || $lot->ends_at->diffInSeconds($now, true) > $window || $now >= $lot->ends_at) {
            return;
        }
        if ($auction->max_extensions !== null && $lot->extension_count >= $auction->max_extensions) {
            return;
        }
        $newEnd = $now->addSeconds((int) $auction->extension_seconds);
        if ($newEnd <= $lot->ends_at) {
            return;
        }
        AuctionExtension::create(['auction_lot_id' => $lot->id, 'auction_bid_id' => $bid->id, 'previous_ends_at' => $lot->ends_at, 'new_ends_at' => $newEnd]);
        $lot->forceFill(['ends_at' => $newEnd, 'extension_count' => $lot->extension_count + 1, 'original_ends_at' => $lot->original_ends_at ?? $lot->ends_at])->save();
        $events[] = $this->event($lot, 'extended', ['ends_at' => $newEnd->toIso8601String(), 'extension_count' => $lot->extension_count]);
    }

    /**
     * Proxy bidding (eBay-style): the highest maximum leads at the lowest price needed to beat the runner-up.
     * Iterates until no active auto-bid can beat the current price. Never bids above a bidder's maximum.
     */
    private function resolveProxies(AuctionLot $lot, CarbonImmutable $now, array &$events): void
    {
        $inc = Money::of($lot->min_increment);
        for ($guard = 0; $guard < 200; $guard++) {
            $autos = AuctionAutoBid::where('auction_lot_id', $lot->id)->where('status', 'active')->orderByDesc('max_amount')->orderBy('set_at')->orderBy('id')->get();
            // Exhaust maximums that can no longer meet the next bid (except the leader's, which is already in front).
            foreach ($autos as $a) {
                if ($a->user_id !== $lot->current_leader_id && Money::of($a->max_amount)->lessThan($lot->minNextBid())) {
                    $a->update(['status' => 'exhausted']);
                }
            }
            $autos = $autos->filter(fn ($a) => $a->fresh()->status === 'active')->values();
            $challenger = $autos->first(fn ($a) => $a->user_id !== $lot->current_leader_id);
            if (! $challenger) {
                return;
            }
            $leaderAuto = $autos->first(fn ($a) => $a->user_id === $lot->current_leader_id);
            $cMax = Money::of($challenger->max_amount);

            if ($leaderAuto) {
                $lMax = Money::of($leaderAuto->max_amount);
                $leaderWinsTie = $leaderAuto->set_at < $challenger->set_at || ($leaderAuto->set_at == $challenger->set_at && strcmp($leaderAuto->id, $challenger->id) < 0);
                if ($lMax->greaterThan($cMax) || ($lMax->equals($cMax) && $leaderWinsTie)) {
                    // Leader defends at the lowest winning amount (challenger's max + increment, capped by leader max).
                    $target = $cMax->plus($inc)->min($lMax);
                    if ($target->greaterThan(Money::of($lot->current_price))) {
                        $this->accept($lot, $leaderAuto->user_id, $target, 'auto', null, null, $now, $events);
                    }
                    $challenger->update(['status' => 'exhausted']);

                    return;
                }
                // Challenger's maximum is higher (or equal but earlier): take the lead just above the leader's max.
                $target = $lMax->plus($inc)->min($cMax);
                $this->accept($lot, $challenger->user_id, $target, 'auto', null, null, $now, $events);
                $leaderAuto->update(['status' => 'exhausted']);

                continue;
            }
            // Leader has no proxy: challenger bids the minimum next amount.
            $this->accept($lot, $challenger->user_id, $lot->minNextBid()->min($cMax), 'auto', null, null, $now, $events);
        }
    }

    public function event(AuctionLot $lot, string $type, array $payload, ?string $actorId = null): AuctionLotEvent
    {
        $seq = $lot->last_event_sequence + 1;
        $lot->forceFill(['last_event_sequence' => $seq])->save();

        return AuctionLotEvent::create(['auction_lot_id' => $lot->id, 'sequence' => $seq, 'type' => $type, 'payload' => $payload, 'actor_id' => $actorId]);
    }

    /** @param list<AuctionLotEvent> $events */
    public function broadcast(AuctionLot $lot, array $events): void
    {
        if ($events === []) {
            return;
        }
        $last = end($events);
        DB::afterCommit(fn () => event(new LotUpdated($lot->id, $last->sequence, $last->type, $events)));
    }
}
