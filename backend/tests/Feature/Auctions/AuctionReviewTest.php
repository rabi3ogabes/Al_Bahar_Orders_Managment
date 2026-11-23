<?php

use App\Domain\Auctions\Events\AuctionOutbid;
use App\Domain\Auctions\Models\AuctionAutoBid;
use App\Domain\Auctions\Models\AuctionBid;
use App\Domain\Auctions\Models\AuctionBidder;
use App\Domain\Auctions\Models\AuctionWinner;
use App\Domain\Auctions\Services\BidEngine;
use App\Domain\Auctions\Services\LotService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\Branch;
use App\Support\DomainException;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'Asia/Qatar'));
    $this->w = auctionWorld();
});

it('stops a blocked bidder: running maximum cancelled, bids struck, never the winner', function () {
    $w = $this->w;
    bid($w, $w->u1, '120.00');
    app(BidEngine::class)->setAutoBid($w->u2, $w->lot->id, Money::of('400.00')); // u2 leads at 130 with a proxy
    expect($w->lot->fresh()->current_leader_id)->toBe($w->u2->id);

    $bidder = AuctionBidder::where('user_id', $w->u2->id)->firstOrFail();
    app(LotService::class)->blockBidder($bidder, $w->admin, 'Shill bidding');

    $lot = $w->lot->fresh();
    expect($lot->current_leader_id)->toBe($w->u1->id)->and($lot->current_price)->toBe('120.00')->and($lot->bid_count)->toBe(1)
        ->and(AuctionAutoBid::where('user_id', $w->u2->id)->where('status', 'active')->exists())->toBeFalse()
        ->and(AuditLog::where('action', 'auction.bidder_blocked')->where('actor_id', $w->admin->id)->exists())->toBeTrue();
    expect(fn () => bid($w, $w->u2, '500.00'))->toThrow(fn (DomainException $e) => expect($e->errorCode)->toBe('bid_blocked'));
});

it('never picks a blocked bidder as winner even if their bid was left accepted', function () {
    $w = auctionWorld(['reserve_price' => null]);
    bid($w, $w->u1, '100.00');
    bid($w, $w->u2, '200.00');
    AuctionBidder::where('user_id', $w->u2->id)->update(['status' => 'blocked']);
    expect(app(LotService::class)->winningBid($w->lot->fresh())->user_id)->toBe($w->u1->id);
});

it('refuses to block a bidder without the override permission', function () {
    $bidder = AuctionBidder::where('user_id', $this->w->u1->id)->firstOrFail();
    $sales = staffWithRole('sales_employee', Branch::factory()->create());
    expect(fn () => app(LotService::class)->blockBidder($bidder, $sales, 'x'))->toThrow(DomainException::class);
    expect($bidder->fresh()->status)->not->toBe('blocked');
});

it('keeps admin reasons off the public event feed', function () {
    app(LotService::class)->pause($this->w->lot, $this->w->admin, 'Suspected shill bidder u1');
    $json = json_encode($this->getJson("/api/v1/auctions/lots/{$this->w->lot->id}/events")->assertOk()->json());
    expect($json)->not->toContain('Suspected shill')->and($json)->toContain('paused');
});

it('relisting an unpaid lot starts a clean round: old bids cannot win it again', function () {
    $w = auctionWorld([], ['unpaid_winner_policy' => 'relist']);
    bid($w, $w->u1, '350.00');
    $this->travel(11)->minutes();
    app(LotService::class)->tick();
    expect($w->auction->fresh()->status)->toBe('closed');
    $this->travel(49)->hours();
    app(LotService::class)->tick();

    $lot = $w->lot->fresh();
    expect($lot->status)->toBe('pending')->and($lot->bid_count)->toBe(0)->and($lot->extension_count)->toBe(0)
        ->and($w->auction->fresh()->status)->toBe('live')
        ->and(app(LotService::class)->winningBid($lot))->toBeNull();
    app(LotService::class)->open($lot, null, 600);
    $this->travel(11)->minutes();
    app(LotService::class)->tick();
    expect($w->lot->fresh()->status)->toBe('unsold')->and(AuctionWinner::where('auction_lot_id', $lot->id)->where('status', 'pending_payment')->exists())->toBeFalse();
});

it('does not tell a leader they were outbid when their own maximum took the lead straight back', function () {
    Event::fake([AuctionOutbid::class]);
    $w = $this->w;
    app(BidEngine::class)->setAutoBid($w->u1, $w->lot->id, Money::of('300.00'));
    bid($w, $w->u2, '150.00'); // u1's proxy defends
    expect($w->lot->fresh()->current_leader_id)->toBe($w->u1->id);
    Event::assertDispatched(AuctionOutbid::class, fn ($e) => $e->userId === $w->u2->id);
    Event::assertNotDispatched(AuctionOutbid::class, fn ($e) => $e->userId === $w->u1->id);
    Event::assertDispatchedTimes(AuctionOutbid::class, 1);
});

it('still tells a plain leader they were outbid', function () {
    Event::fake([AuctionOutbid::class]);
    bid($this->w, $this->w->u1, '100.00');
    bid($this->w, $this->w->u2, '110.00');
    Event::assertDispatched(AuctionOutbid::class, fn ($e) => $e->userId === $this->w->u1->id && $e->amount !== '');
});

it('caps anti-sniping extensions at the platform limit even when the auction sets none', function () {
    config(['platform.auctions.hard_max_extensions' => 2]);
    $w = auctionWorld(['ends_at' => now()->addMinutes(3)], ['max_extensions' => null, 'extension_seconds' => 120, 'extension_window_seconds' => 120,
        'translations' => ['en' => ['title' => 'Cap'], 'ar' => ['title' => 'سقف']]]);
    foreach ([0, 1, 2, 3, 4] as $i) {
        $lot = $w->lot->fresh();
        $this->travelTo($lot->ends_at->subSeconds(30));
        bid($w, $i % 2 ? $w->u1 : $w->u2, (string) (100 + $i * 10).'.00');
    }
    expect($w->lot->fresh()->extension_count)->toBe(2);
});

it('closes a buy-now bid at the buy-now price, never above, even when the next increment would exceed it', function () {
    $w = auctionWorld(['buy_now_price' => '500.00', 'reserve_price' => null]);
    bid($w, $w->u1, '480.00'); // next minimum is 490, still below 500
    $res = bid($w, $w->u2, '900.00');
    $lot = $w->lot->fresh();
    expect($res['bid']->amount)->toBe('500.00')->and($lot->status)->toBe('sold');
    expect(AuctionWinner::where('auction_lot_id', $lot->id)->value('amount'))->toBe('500.00');
});

it('accepts buy-now even when current price plus increment passes the buy-now price', function () {
    $w = auctionWorld(['buy_now_price' => '500.00', 'min_increment' => '50.00', 'reserve_price' => null]);
    bid($w, $w->u1, '460.00'); // minimum next would be 510 > 500
    bid($w, $w->u2, '500.00');
    expect($w->lot->fresh()->status)->toBe('sold')->and(AuctionBid::where('auction_lot_id', $w->lot->id)->where('status', 'rejected')->count())->toBe(0);
});

it('records a second-chance offer in the audit log', function () {
    $w = $this->w;
    bid($w, $w->u1, '310.00');
    bid($w, $w->u2, '350.00');
    $this->travel(11)->minutes();
    app(LotService::class)->tick();
    $this->travel(49)->hours();
    app(LotService::class)->tick();
    expect(AuditLog::where('action', 'auction.second_chance_offered')->count())->toBe(1);
});

it('does not offer the second chance to a blocked bidder', function () {
    $w = $this->w;
    bid($w, $w->u1, '310.00');
    bid($w, $w->u2, '350.00');
    $this->travel(11)->minutes();
    app(LotService::class)->tick();
    AuctionBidder::where('user_id', $w->u1->id)->update(['status' => 'blocked']);
    $this->travel(49)->hours();
    app(LotService::class)->tick();
    expect(AuctionWinner::where('auction_lot_id', $w->lot->id)->where('status', 'pending_payment')->exists())->toBeFalse()->and($w->lot->fresh()->status)->toBe('unsold');
});
