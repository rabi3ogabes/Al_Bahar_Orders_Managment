<?php

use App\Domain\Auctions\Models\AuctionBid;
use App\Domain\Auctions\Services\AuctionService;
use App\Domain\Auctions\Services\BidEngine;
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
