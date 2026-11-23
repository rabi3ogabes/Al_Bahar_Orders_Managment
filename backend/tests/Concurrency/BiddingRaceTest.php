<?php

use App\Domain\Auctions\Models\AuctionBid;
use App\Domain\Auctions\Models\AuctionBidder;
use App\Domain\Auctions\Services\AuctionService;
use App\Domain\Auctions\Services\BidEngine;
use App\Domain\Auctions\Services\LotService;
use App\Domain\Identity\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

uses(TestCase::class, DatabaseTruncation::class);

it('accepts exactly one of many simultaneous identical bids and keeps bid numbers gap-free', function () {
    $w = auctionWorld();
    $users = collect(range(1, 8))->map(function () use ($w) {
        $u = User::factory()->create();
        app(AuctionService::class)->register($u, $w->auction);

        return $u;
    });

    $codes = race(8, fn (int $i) => (bool) app(BidEngine::class)->placeBid($users[$i], $w->lot->id, Money::of('100.00'), 'race-'.$i));

    $accepted = AuctionBid::where('status', 'accepted')->orderBy('bid_number')->get();
    expect(count(array_filter($codes, fn ($c) => $c === 0)))->toBe(1)
        ->and($accepted)->toHaveCount(1)->and($accepted[0]->bid_number)->toBe(1)
        ->and(AuctionBid::where('status', 'rejected')->count())->toBe(7)
        ->and($w->lot->fresh()->last_bid_number)->toBe(1);
})->skip(! function_exists('pcntl_fork'), 'pcntl not available');

it('settles simultaneous proxy maximums to one consistent result: highest maximum leads at the runner-up plus one increment, numbers gap-free', function () {
    $w = auctionWorld(['reserve_price' => null]);
    $users = collect(range(1, 4))->map(function () use ($w) {
        $u = User::factory()->create();
        app(AuctionService::class)->register($u, $w->auction);

        return $u;
    });
    $maxima = ['200.00', '350.00', '500.00', '420.00'];

    race(4, fn (int $i) => (bool) app(BidEngine::class)->setAutoBid($users[$i], $w->lot->id, Money::of($maxima[$i])));

    $lot = $w->lot->fresh();
    $numbers = AuctionBid::where('auction_lot_id', $lot->id)->where('status', 'accepted')->orderBy('bid_number')->pluck('bid_number')->all();
    expect($lot->current_leader_id)->toBe($users[2]->id)->and($lot->current_price)->toBe('430.00')
        ->and($numbers)->toBe(range(1, count($numbers)))->and($lot->last_bid_number)->toBe(count($numbers))
        ->and((float) AuctionBid::where('user_id', $users[0]->id)->max('amount'))->toBeLessThanOrEqual(200.0);
})->skip(! function_exists('pcntl_fork'), 'pcntl not available');

it('blocking a bidder while bids arrive leaves a lot whose leader is never the blocked bidder', function () {
    $w = auctionWorld(['reserve_price' => null]);
    $bidder = AuctionBidder::where('user_id', $w->u1->id)->firstOrFail();
    app(BidEngine::class)->placeBid($w->u1, $w->lot->id, Money::of('100.00'), 'blk-first');

    race(2, function (int $i) use ($w, $bidder) {
        if ($i === 0) {
            app(LotService::class)->blockBidder($bidder, $w->admin, 'Fraud');

            return true;
        }

        return (bool) app(BidEngine::class)->placeBid($w->u2, $w->lot->id, Money::of('110.00'), 'blk-second');
    });

    $lot = $w->lot->fresh();
    expect($lot->current_leader_id)->not->toBe($w->u1->id)
        ->and(AuctionBid::where('user_id', $w->u1->id)->where('status', 'accepted')->count())->toBe(0);
})->skip(! function_exists('pcntl_fork'), 'pcntl not available');
