<?php

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Jobs\SendNotificationJob;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Orders\Models\Order;
use Illuminate\Support\Facades\Queue;

/* Response details the Flutter app relies on (see mobile/docs/API_ASSUMPTIONS.md). */

it('filters orders by several statuses at once', function () {
    $w = commerceWorld();
    $ids = [];
    foreach (['confirmed', 'processing', 'cancelled'] as $status) {
        $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v2->id, 'quantity' => 1])->assertCreated();
        $id = quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->assertCreated()->json('data.order.id');
        Order::whereKey($id)->update(['status' => $status]);
    }

    expect($this->getJson('/api/v1/orders?status=confirmed,processing')->assertOk()->json('meta.total'))->toBe(2)
        ->and($this->getJson('/api/v1/orders?status[]=cancelled')->json('meta.total'))->toBe(1)
        ->and($this->getJson('/api/v1/orders')->json('meta.total'))->toBe(3);
});

it('rejects an unknown coupon when it is entered in the cart', function () {
    commerceWorld();
    $this->putJson('/api/v1/cart', ['coupon_code' => 'NOPE'])
        ->assertStatus(422)->assertJsonPath('errors.coupon_code.0', __('checkout.coupon_invalid'));
});

it('marks the viewer\'s own bids and reports their highest bid per lot', function () {
    $w = auctionWorld();
    bid($w, $w->u1, '100.00');
    bid($w, $w->u2, '110.00');
    bid($w, $w->u1, '120.00');

    actingAsCustomer($w->u1);
    $rows = $this->getJson("/api/v1/auctions/lots/{$w->lot->id}/bids")->assertOk()->json('data');
    expect(collect($rows)->where('is_mine', true)->count())->toBe(2)
        ->and(collect($rows)->firstWhere('is_mine', false)['bidder'])->not->toBe(__('auctions.you'));

    $mine = $this->getJson('/api/v1/me/auctions/bids')->assertOk()->json('data.0');
    expect($mine['my_highest_bid']['amount'])->toBe('120.00')->and($mine['my_last_bid_at'])->not->toBeNull();

    actingAsCustomer($w->u2); // u1 is leading; the other bidder outbids
    $echo = $this->postJson("/api/v1/auctions/lots/{$w->lot->id}/bids", ['amount' => '130.00'], ['Idempotency-Key' => str_repeat('k', 20)])->assertCreated()->json('data.bid');
    expect($echo['is_mine'])->toBeTrue()->and($echo['bidder'])->toBe(__('auctions.you'));
});

it('puts the in-app notification id in push payloads so a tap can mark it read', function () {
    Queue::fake();
    $user = User::factory()->create();
    app(NotificationService::class)->notify($user, 'order.confirmed', ['order_number' => 'AB100001', 'deep_link' => 'albahar://orders/1'], ['push', 'in_app']);

    $inApp = Notification::where('user_id', $user->id)->firstOrFail();
    Queue::assertPushed(SendNotificationJob::class, fn ($job) => $job->notificationId === $inApp->id);
});
