<?php

use App\Domain\Auctions\Models\AuctionBid;
use App\Domain\Auctions\Models\AuctionBidder;
use App\Domain\Auctions\Models\AuctionExtension;
use App\Domain\Auctions\Models\AuctionWinner;
use App\Domain\Auctions\Services\AuctionService;
use App\Domain\Auctions\Services\BidEngine;
use App\Domain\Auctions\Services\LotService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Services\PaymentService;
use App\Support\DomainException;
use App\Support\Money;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => $this->w = auctionWorld());

it('accepts valid bids with monotonically increasing bid numbers', function () {
    bid($this->w, $this->w->u1, '100.00');
    bid($this->w, $this->w->u2, '110.00');
    $lot = $this->w->lot->fresh();
    expect($lot->current_price)->toBe('110.00')->and($lot->current_leader_id)->toBe($this->w->u2->id)->and($lot->last_bid_number)->toBe(2)
        ->and($lot->minNextBid()->toDecimal())->toBe('120.00')
        ->and(AuctionBid::where('status', 'accepted')->orderBy('bid_number')->pluck('bid_number')->all())->toBe([1, 2]);
});

it('rejects bids below the minimum and records the rejected attempt', function () {
    bid($this->w, $this->w->u1, '100.00');
    expect(fn () => bid($this->w, $this->w->u2, '105.00'))->toThrow(fn (DomainException $e) => expect($e->errorCode)->toBe('bid_outbid_min'));
    expect(AuctionBid::where('status', 'rejected')->value('rejection_reason'))->toBe('outbid_min');
});

it('rejects bids after close using server time, regardless of client countdown', function () {
    $this->travel(11)->minutes();
    expect(fn () => bid($this->w, $this->w->u1, '200.00'))->toThrow(fn (DomainException $e) => expect($e->errorCode)->toBe('bid_closed'));
});

it('rejects unregistered and blocked bidders', function () {
    $stranger = User::factory()->create();
    expect(fn () => bid($this->w, $stranger, '100.00'))->toThrow(fn (DomainException $e) => expect($e->errorCode)->toBe('bid_not_registered'));
    AuctionBidder::where('user_id', $this->w->u1->id)->update(['status' => 'blocked']);
    expect(fn () => bid($this->w, $this->w->u1, '100.00'))->toThrow(fn (DomainException $e) => expect($e->errorCode)->toBe('bid_blocked'));
});

it('treats a duplicate submission (same idempotency key) as one bid', function () {
    $a = bid($this->w, $this->w->u1, '100.00', 'dup-key-000000001');
    $b = bid($this->w, $this->w->u1, '100.00', 'dup-key-000000001');
    expect($b['replayed'])->toBeTrue()->and($b['bid']->id)->toBe($a['bid']->id)->and(AuctionBid::count())->toBe(1);
});

it('resolves competing auto-bids at the lowest winning price without exceeding maximums', function () {
    $engine = app(BidEngine::class);
    $engine->setAutoBid($this->w->u1, $this->w->lot->id, Money::of('250.00'));
    expect($this->w->lot->fresh()->current_price)->toBe('100.00')->and($this->w->lot->fresh()->current_leader_id)->toBe($this->w->u1->id);

    $engine->setAutoBid($this->w->u2, $this->w->lot->id, Money::of('400.00'));
    $lot = $this->w->lot->fresh();
    expect($lot->current_leader_id)->toBe($this->w->u2->id)->and($lot->current_price)->toBe('260.00'); // u1 max + increment
    expect((float) AuctionBid::where('user_id', $this->w->u1->id)->max('amount'))->toBeLessThanOrEqual(250.0); // never above u1's max

    // A manual bid below the leader's maximum is immediately outbid by the proxy.
    $u3 = User::factory()->create();
    app(AuctionService::class)->register($u3, $this->w->auction);
    bid($this->w, $u3, '300.00');
    expect($this->w->lot->fresh()->current_leader_id)->toBe($this->w->u2->id)->and($this->w->lot->fresh()->current_price)->toBe('310.00');
});

it('breaks equal maximums in favour of the earliest maximum', function () {
    $engine = app(BidEngine::class);
    $engine->setAutoBid($this->w->u1, $this->w->lot->id, Money::of('300.00'));
    $this->travel(2)->seconds();
    $engine->setAutoBid($this->w->u2, $this->w->lot->id, Money::of('300.00'));
    $lot = $this->w->lot->fresh();
    expect($lot->current_leader_id)->toBe($this->w->u1->id)->and($lot->current_price)->toBe('300.00');
});

it('extends the end time for bids inside the anti-sniping window and records the extension', function () {
    $this->travel(570)->seconds(); // 30s before the end, window is 120s
    $before = $this->w->lot->fresh()->ends_at;
    bid($this->w, $this->w->u1, '100.00');
    $lot = $this->w->lot->fresh();
    expect($lot->ends_at->greaterThan($before))->toBeTrue()->and($lot->extension_count)->toBe(1)
        ->and(AuctionExtension::count())->toBe(1)->and($lot->events()->pluck('type')->all())->toContain('extended');
});

it('respects the maximum number of extensions', function () {
    $w = auctionWorld([], ['max_extensions' => 1, 'translations' => ['en' => ['title' => 'Limited'], 'ar' => ['title' => 'محدود']]]);
    $this->travel(570)->seconds();
    bid($w, $w->u1, '100.00');
    $this->travel(100)->seconds();
    bid($w, $w->u2, '110.00');
    expect($w->lot->fresh()->extension_count)->toBe(1);
});

it('closes unsold when the reserve is not met, and sold with a reproducible winner otherwise', function () {
    bid($this->w, $this->w->u1, '150.00');
    $this->travel(11)->minutes();
    app(LotService::class)->tick();
    expect($this->w->lot->fresh()->status)->toBe('unsold')->and(AuctionWinner::count())->toBe(0);

    $w = auctionWorld();
    bid($w, $w->u1, '200.00');
    bid($w, $w->u2, '320.00');
    $this->travel(11)->minutes();
    app(LotService::class)->tick();
    $winner = AuctionWinner::where('auction_lot_id', $w->lot->id)->firstOrFail();
    expect($w->lot->fresh()->status)->toBe('sold')->and($winner->user_id)->toBe($w->u2->id)->and($winner->amount)->toBe('320.00')
        ->and($winner->bid_id ?? $winner->auction_bid_id)->toBe(app(LotService::class)->winningBid($w->lot->fresh())->id);
});

it('offers the lot to the next bidder when the winner does not pay in time', function () {
    bid($this->w, $this->w->u1, '310.00');
    bid($this->w, $this->w->u2, '350.00');
    $this->travel(11)->minutes();
    app(LotService::class)->tick();
    $this->travel(49)->hours();
    app(LotService::class)->tick();
    $winners = AuctionWinner::where('auction_lot_id', $this->w->lot->id)->orderBy('rank')->get();
    expect($winners->pluck('status')->all())->toBe(['defaulted', 'pending_payment'])->and($winners[1]->user_id)->toBe($this->w->u1->id)->and($winners[1]->amount)->toBe('310.00');
});

it('lets the winner check out and marks the win paid on payment', function () {
    $w = commerceWorld();
    $auction = auctionWorld();
    bid($auction, $auction->u1, '400.00');
    $this->travel(11)->minutes();
    app(LotService::class)->tick();
    $winner = AuctionWinner::firstOrFail();

    Sanctum::actingAs($auction->u1, ['customer']);
    $this->postJson("/api/v1/me/auctions/wins/{$winner->id}/checkout", ['fulfillment_type' => 'delivery', 'address_id' => $w->address->id, 'payment_method' => 'cod'])
        ->assertStatus(422)->assertJsonPath('code', 'payment_method_unavailable'); // auction COD disabled by default
    $res = $this->postJson("/api/v1/me/auctions/wins/{$winner->id}/checkout", ['fulfillment_type' => 'pickup', 'branch_id' => $w->a->id, 'payment_method' => 'pay_at_pickup'])->assertCreated();
    expect($res->json('data.order.totals.grand_total.amount'))->toBe('400.00')->and($winner->fresh()->order_id)->not->toBeNull();

    $payment = Payment::where('order_id', $winner->fresh()->order_id)->first();
    $order = Order::find($winner->fresh()->order_id);
    $pickupStaff = staffWithRole('pickup_employee', $w->a);
    app(PaymentService::class)->recordCollection($payment, $order->fulfillments()->first(), $pickupStaff, 'cash', '400.00');
    expect($winner->fresh()->status)->toBe('paid');
});

it('replays lot events after a sequence for reconnecting clients without leaking identities', function () {
    bid($this->w, $this->w->u1, '100.00');
    bid($this->w, $this->w->u2, '120.00');
    $events = $this->getJson("/api/v1/auctions/lots/{$this->w->lot->id}/events?after_sequence=1")->assertOk()->json();
    expect(collect($events['data'])->pluck('type')->all())->toBe(['bid_accepted', 'bid_accepted'])
        ->and($events['meta']['last_sequence'])->toBe(3)
        ->and(json_encode($events))->not->toContain($this->w->u1->id)->not->toContain('previous_leader');

    $lot = $this->getJson("/api/v1/auctions/lots/{$this->w->lot->id}")->assertOk()->json('data');
    expect($lot)->not->toHaveKey('reserve_price')->and($lot['reserve_met'])->toBeFalse()->and($lot['min_next_bid']['amount'])->toBe('130.00');
    expect($this->getJson("/api/v1/auctions/lots/{$this->w->lot->id}/bids")->json('data.0.bidder'))->toStartWith('Bidder ');
});

it('audits auctioneer pause/resume/cancel and freezes the clock while paused', function () {
    $lots = app(LotService::class);
    $lots->pause($this->w->lot, $this->w->admin, 'Technical issue');
    expect(fn () => bid($this->w, $this->w->u1, '100.00'))->toThrow(DomainException::class);
    $this->travel(20)->minutes(); // would have ended
    $lots->resume($this->w->lot->fresh(), $this->w->admin);
    bid($this->w, $this->w->u1, '100.00');
    $lots->cancel($this->w->lot->fresh(), $this->w->admin, 'Item withdrawn by consignor');
    expect(AuditLog::where('action', 'like', 'auction.lot_%')->pluck('action')->all())->toContain('auction.lot_paused', 'auction.lot_resumed', 'auction.lot_cancelled');
    expect(fn () => $lots->pause($this->w->lot->fresh(), staffWithRole('sales_employee', Branch::factory()->create()), 'x'))->toThrow(DomainException::class);
});

it('exposes bidding over the API with idempotency', function () {
    Sanctum::actingAs($this->w->u1, ['customer']);
    $this->postJson("/api/v1/auctions/lots/{$this->w->lot->id}/bids", ['amount' => '100'])->assertStatus(400);
    $this->postJson("/api/v1/auctions/lots/{$this->w->lot->id}/bids", ['amount' => '100'], ['Idempotency-Key' => 'api-bid-0000000001'])->assertCreated()->assertJsonPath('data.lot.my_status', 'leading');
    $this->postJson("/api/v1/auctions/lots/{$this->w->lot->id}/bids", ['amount' => '100'], ['Idempotency-Key' => 'api-bid-0000000001'])->assertOk()->assertJsonPath('data.replayed', true);
});
