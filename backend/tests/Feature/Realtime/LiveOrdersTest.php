<?php

use App\Broadcasting\LiveOrdersChannel;
use App\Domain\Orders\Events\LiveOrderEvent;
use App\Domain\Orders\Models\Order;
use App\Domain\Partners\Services\PartnerAccess;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Event;

function liveUnlockedPartner(): string
{
    $a = app(PartnerAccess::class);
    $a->setPassword('Correct-Horse-9');
    $a->setEnabled(true);
    test()->postJson("/partner/{$a->token()}/unlock", ['password' => 'Correct-Horse-9'])->assertOk();

    return $a->token();
}

it('authorises the live-orders channels by the orders.view scope', function () {
    $w = commerceWorld();
    $channel = new LiveOrdersChannel;
    $global = staffWithRole('super_admin');
    $branchStaff = staffWithRole('order_manager', $w->a);

    expect($channel->join($global))->toBeTrue()
        ->and($channel->join($global, $w->b->id))->toBeTrue()
        ->and($channel->join($branchStaff))->toBeFalse()                 // branch staff do not get the everything channel
        ->and($channel->join($branchStaff, $w->a->id))->toBeTrue()
        ->and($channel->join($branchStaff, $w->b->id))->toBeFalse()      // nor another branch
        ->and($channel->join($w->customer))->toBeFalse()
        ->and($channel->join($w->customer, $w->a->id))->toBeFalse();
});

it('registers both channel patterns', function () {
    $names = collect(app(BroadcastManager::class)->driver()->getChannels() ?? [])->keys()->all();
    expect($names)->toContain('live-orders')->toContain('live-orders.{branch}');
});

it('broadcasts a new order with a small first-name-only payload on the global and the branch channel', function () {
    Event::fake([LiveOrderEvent::class]);
    $w = commerceWorld();
    $this->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1]);
    $orderId = quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->json('data.order.id');
    $order = Order::find($orderId);

    Event::assertDispatched(LiveOrderEvent::class, function (LiveOrderEvent $e) use ($order) {
        $p = $e->payload;
        expect(array_keys($p))->toEqualCanonicalizing(['id', 'type', 'order_id', 'number', 'status', 'previous', 'branch', 'branch_ar', 'customer', 'total', 'currency', 'payment_method', 'fulfilment', 'at'])
            ->and($p['type'])->toBe('placed')->and($p['order_id'])->toBe($order->id)->and($p['number'])->toBe($order->number)
            ->and($p['payment_method'])->toBe('cod')->and($p['fulfilment'])->toBe($order->fulfillment_type)
            ->and($p['total'])->toBe((string) $order->grand_total)->and($p['previous'])->toBeNull()
            ->and($p['customer'])->not->toContain(' ');
        $names = array_map(fn ($c) => $c->name, $e->broadcastOn());

        return $e->broadcastAs() === 'order.live' && in_array('private-live-orders', $names, true)
            && count(array_filter($names, fn ($n) => str_starts_with($n, 'private-live-orders.'))) === count($e->branchIds) && $e->branchIds !== [];
    });
});

it('broadcasts order status changes with the previous status', function () {
    $w = commerceWorld();
    Event::fake([LiveOrderEvent::class]);
    readyOrder($w);

    Event::assertDispatched(LiveOrderEvent::class, fn (LiveOrderEvent $e) => $e->payload['type'] === 'status' && $e->payload['previous'] !== null && $e->payload['status'] !== $e->payload['previous']
        && str_starts_with($e->payload['id'], 'status:'));
});

it('keeps the partner events endpoint behind the password session', function () {
    $a = app(PartnerAccess::class);
    $a->setPassword('Correct-Horse-9');
    $a->setEnabled(true);
    $this->getJson("/partner/{$a->token()}/events")->assertStatus(401);
    $this->getJson('/partner/wrong/events')->assertNotFound();
});

it('hands out a cursor first, then returns only newer orders and status changes without personal data', function () {
    $token = liveUnlockedPartner();
    $first = $this->getJson("/partner/{$token}/events")->assertOk()->assertJsonPath('data.events', [])->json('data.cursor');
    expect($first)->toBeInt();

    $w = commerceWorld();
    readyOrder($w);

    $data = $this->getJson("/partner/{$token}/events?since={$first}")->assertOk()->json('data');
    $types = collect($data['events'])->pluck('type');
    expect($types)->toContain('placed')->toContain('status')
        ->and($data['cursor'])->toBeGreaterThanOrEqual($first);
    foreach ($data['events'] as $ev) {
        expect($ev)->not->toHaveKeys(['phone', 'address', 'email', 'customer_name'])->and($ev['customer'])->not->toContain(' ');
    }
    $status = collect($data['events'])->firstWhere('type', 'status');
    expect($status['id'])->toStartWith('status:')->and($status['previous'])->not->toBeNull();

    $later = $this->getJson('/partner/'.$token.'/events?since='.((int) (microtime(true) * 1000) + 3_600_000))->assertOk()->json('data.events');
    expect($later)->toBe([]);
});

it('validates the cursor and rate limits the partner events endpoint', function () {
    $token = liveUnlockedPartner();
    $this->getJson("/partner/{$token}/events?since=abc")->assertStatus(422);
    $this->getJson("/partner/{$token}/events?since=-5")->assertStatus(422);
    foreach (range(1, 38) as $i) {
        $this->getJson("/partner/{$token}/events")->assertOk();
    }
    $this->getJson("/partner/{$token}/events")->assertStatus(429);
});
