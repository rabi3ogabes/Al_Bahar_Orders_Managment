<?php

use App\Broadcasting\BranchChannel;
use App\Broadcasting\DriverChannel;
use App\Broadcasting\FleetChannel;
use App\Broadcasting\LiveOrdersChannel;
use App\Broadcasting\OrderChannel;
use App\Domain\Fulfillment\Events\DispatchUpdated;
use App\Domain\Fulfillment\Events\DriverJobUpdated;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Events\LiveOrderEvent;
use App\Domain\Orders\Events\OrderUpdated;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\OrderService;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
| One real-time contract for order progress: every change of an order, one of its branch parts or one of its deliveries reaches the
| customer (private-users.{id}), the branch (private-branches.{id}) and the driver (private-drivers.{id}) with the same shape:
| order_id, number, status, fulfillment_id, delivery_status, seq (grows by one per change) and at. See docs/qa/REALTIME_ORDER_STATUS.md.
*/

const RT_EVENTS = [OrderUpdated::class, DispatchUpdated::class, DriverJobUpdated::class];

/** Starts counting from nothing: a fresh fake of the three message classes. */
function rtReset(): void
{
    Event::fake(RT_EVENTS);
    test()->rtSeen = [];
}

/** The messages sent since the last call, as [customer, branch, driver] message lists. */
function rtHeard(): array
{
    $seen = test()->rtSeen ?? [];
    $out = [];
    foreach (['customer' => OrderUpdated::class, 'branch' => DispatchUpdated::class, 'driver' => DriverJobUpdated::class] as $who => $class) {
        $all = Event::dispatched($class)->map(fn ($args) => $args[0])->values();
        $out[$who] = $all->slice($seen[$class] ?? 0)->values()->all();
        $seen[$class] = $all->count();
    }
    test()->rtSeen = $seen;

    return $out;
}

// The messages go out when the response has been sent (defer): HTTP tests see them as soon as the request call returns. A test that calls
// a service directly has no response, so it either calls $this->withoutDefer() or runs the deferred queue itself.
beforeEach(fn () => rtReset());

/** Places a COD order of branch A and returns [order id, fulfillment]. */
function rtPlace(object $w): array
{
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1]);
    $orderId = quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->json('data.order.id');

    return [$orderId, Order::with('fulfillments')->find($orderId)->fulfillments->first()];
}

it('implements the delivery rules of a real-time message: after the commit, straight to the socket server', function () {
    foreach ([OrderUpdated::class, DispatchUpdated::class, DriverJobUpdated::class, LiveOrderEvent::class] as $class) {
        expect(is_subclass_of($class, ShouldDispatchAfterCommit::class))->toBeTrue("{$class} must wait for the commit")
            ->and(is_subclass_of($class, ShouldBroadcastNow::class))->toBeTrue("{$class} must not wait for a queue worker");
    }
});

it('tells the customer, the branch and the driver at every step, one growing version for all', function () {
    $w = commerceWorld();
    $driver = driverFor($w->a);
    [$orderId, $f] = rtPlace($w);
    $order = Order::find($orderId);

    $heard = rtHeard();
    // Placed: the customer's other devices and the branch both hear it, the driver does not.
    expect($heard['customer'])->toHaveCount(1)->and($heard['branch'])->toHaveCount(1)->and($heard['driver'])->toBeEmpty();
    $c = $heard['customer'][0]->broadcastWith();
    expect($c)->toMatchArray(['order_id' => $orderId, 'number' => $order->number, 'status' => 'confirmed', 'fulfillment_id' => $f->id, 'fulfillment_status' => 'pending', 'delivery_status' => null, 'seq' => 1])
        ->and($c['fulfillments'])->toBe([['id' => $f->id, 'type' => 'delivery', 'status' => 'pending', 'delivery_status' => null]])
        ->and($heard['customer'][0]->broadcastOn()[0]->name)->toBe('private-users.'.$w->customer->id);
    $b = $heard['branch'][0];
    expect($b->broadcastOn()[0]->name)->toBe('private-branches.'.$w->a->id)->and($b->broadcastAs())->toBe('dispatch.updated')
        ->and($b->broadcastWith())->toMatchArray(['order_id' => $orderId, 'number' => $order->number, 'kind' => 'order', 'status' => 'pending', 'fulfillment_id' => $f->id,
            'fulfillment_type' => 'delivery', 'stage' => 'new', 'delivery_id' => null, 'driver_id' => null, 'seq' => 1]);

    // The branch accepts, prepares and marks it ready: the customer hears EVERY step (the order's own status stays "processing").
    $mgr = actingAsStaff(staffWithRole('order_manager', $w->a));
    $seqs = [1];
    foreach ([['accepted', 'processing', 'preparing'], ['preparing', 'processing', 'preparing'], ['ready', 'processing', 'ready']] as [$to, $orderStatus, $stage]) {
        $this->postJson("/api/v1/admin/fulfillments/{$f->id}/transition", ['status' => $to])->assertOk();
        $heard = rtHeard();
        expect($heard['customer'])->toHaveCount(1)->and($heard['branch'])->toHaveCount(1)->and($heard['driver'])->toBeEmpty();
        $c = $heard['customer'][0]->broadcastWith();
        expect($c)->toMatchArray(['status' => $orderStatus, 'fulfillment_status' => $to])
            ->and($heard['branch'][0]->broadcastWith())->toMatchArray(['kind' => 'order', 'status' => $to, 'stage' => $stage]);
        $seqs[] = $c['seq'];
    }

    // The branch hands it to a driver: the driver hears the new job, the branch and the customer hear the delivery.
    $this->postJson("/api/v1/admin/dispatch/fulfillments/{$f->id}/assign", ['driver_id' => $driver->id])->assertOk();
    $heard = rtHeard();
    $delivery = Delivery::where('order_fulfillment_id', $f->id)->firstOrFail();
    expect($heard['customer'])->toHaveCount(1)->and($heard['driver'])->toHaveCount(1);
    $c = $heard['customer'][0]->broadcastWith();
    expect($c['delivery_status'])->toBe('assigned');
    $seqs[] = $c['seq'];
    $branch = collect($heard['branch'])->map->broadcastWith()->firstWhere('kind', 'delivery');
    expect($branch)->toMatchArray(['status' => 'assigned', 'delivery_id' => $delivery->id, 'driver_id' => $driver->id, 'driver_name' => $driver->name])
        ->and($heard['driver'][0]->broadcastWith())->toMatchArray(['delivery_id' => $delivery->id, 'status' => 'assigned', 'assigned' => true, 'order_id' => $orderId, 'seq' => $c['seq']]);

    // The driver works the job through to the door.
    actingAsStaff($driver);
    foreach (['accept' => ['accepted', 'out_for_delivery'], 'pickup' => ['picked_up', 'out_for_delivery'], 'out-for-delivery' => ['out_for_delivery', 'out_for_delivery']] as $step => [$deliveryStatus, $orderStatus]) {
        $this->postJson("/api/v1/admin/deliveries/{$delivery->id}/{$step}")->assertOk();
        $heard = rtHeard();
        expect($heard['customer'])->toHaveCount(1)->and($heard['driver'])->toHaveCount(1);
        $c = $heard['customer'][0]->broadcastWith();
        expect($c['delivery_status'])->toBe($deliveryStatus)->and($heard['driver'][0]->broadcastWith()['status'])->toBe($deliveryStatus);
        $seqs[] = $c['seq'];
    }
    $this->post("/api/v1/admin/deliveries/{$delivery->id}/deliver", ['proof' => UploadedFile::fake()->image('door.jpg'), 'recipient_name' => 'Noor',
        'cod' => ['tender' => 'cash', 'amount' => (string) $order->fresh()->grand_total]], ['Accept' => 'application/json'])->assertOk();
    $heard = rtHeard();
    $c = $heard['customer'][0]->broadcastWith();
    expect($c)->toMatchArray(['status' => 'completed', 'fulfillment_status' => 'delivered', 'delivery_status' => 'delivered'])
        ->and($heard['driver'][0]->broadcastWith()['status'])->toBe('delivered')
        ->and(collect($heard['branch'])->map->broadcastWith()->pluck('stage')->unique()->all())->toBe(['done']);
    $seqs[] = $c['seq'];

    // The order's version only ever grows, by one per change, and the REST answers carry the same number.
    expect($seqs)->toBe(range(1, count($seqs)));
    $latest = end($seqs);
    actingAsCustomer($w->customer);
    $this->getJson("/api/v1/orders/{$orderId}")->assertOk()->assertJsonPath('data.seq', $latest);
    $this->getJson('/api/v1/orders')->assertOk()->assertJsonPath('data.0.seq', $latest);
    expect(Order::find($orderId)->progress_seq)->toBe($latest);
    expect($mgr->id)->not->toBe($driver->id);
});

it('puts the order version into the staff queue rows and the driver jobs', function () {
    $d = deliveryAt('accepted');
    $number = Order::find($d->orderId)->number;
    $seq = Order::find($d->orderId)->progress_seq;
    expect($seq)->toBeGreaterThan(0);

    $job = collect($this->getJson('/api/v1/admin/dispatch/driver/jobs')->assertOk()->json('data'))->firstWhere('order_number', $number);
    expect($job)->toMatchArray(['order_id' => $d->orderId, 'seq' => $seq]);

    actingAsStaff($d->mgr);
    $row = collect($this->getJson('/api/v1/admin/dispatch/queue?stage=ready')->assertOk()->json('data'))->firstWhere('number', $number);
    expect($row)->toMatchArray(['order_id' => $d->orderId, 'seq' => $seq]);
});

it('keeps each audience to what it may know', function () {
    $d = deliveryAt('out_for_delivery');
    $other = driverFor($d->w->a);
    $customerKeys = ['order_id', 'number', 'status', 'status_label', 'payment_status', 'fulfillment_id', 'fulfillment_status', 'delivery_status', 'fulfillments', 'seq', 'at'];
    $branchKeys = ['order_id', 'number', 'kind', 'order_level', 'status', 'order_status', 'fulfillment_id', 'fulfillment_type', 'fulfillment_status', 'stage', 'delivery_id', 'delivery_status', 'driver_id', 'driver_name', 'seq', 'at'];
    $driverKeys = ['order_id', 'number', 'kind', 'status', 'fulfillment_id', 'delivery_id', 'delivery_status', 'assigned', 'seq', 'at'];

    rtReset();
    actingAsStaff($d->driver);
    $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/fail", ['reason_code' => 'customer_unreachable'])->assertOk();
    $heard = rtHeard();

    expect($heard['customer'])->not->toBeEmpty()->and($heard['branch'])->not->toBeEmpty()->and($heard['driver'])->not->toBeEmpty();
    foreach ($heard['customer'] as $e) {
        expect(array_keys($e->broadcastWith()))->toEqualCanonicalizing($customerKeys);
        $json = json_encode($e->broadcastWith());
        expect($json)->not->toContain($d->driver->id)->not->toContain($d->driver->name)->not->toContain($d->w->a->id);   // no driver, no branch
    }
    foreach ($heard['branch'] as $e) {
        expect(array_keys($e->broadcastWith()))->toEqualCanonicalizing($branchKeys);
        expect(json_encode($e->broadcastWith()))->not->toContain($d->w->customer->name)->not->toContain('+97455123456')->not->toContain('Noor');   // no customer data
        expect(collect($e->broadcastOn())->map->name->all())->each->toMatch('/^private-(branches\.'.$d->w->a->id.'|fleet)$/');
    }
    foreach ($heard['driver'] as $e) {
        expect(array_keys($e->broadcastWith()))->toEqualCanonicalizing($driverKeys);
        expect($e->broadcastOn()[0]->name)->toBe('private-drivers.'.$d->driver->id)->and(json_encode($e->broadcastWith()))->not->toContain($other->id);
    }
});

it('takes the job away from the driver it was handed from, without telling them who got it', function () {
    $w = commerceWorld();
    $first = driverFor($w->a);
    $second = driverFor($w->a);
    [$f] = readyOrder($w);
    $this->postJson("/api/v1/admin/dispatch/fulfillments/{$f->id}/assign", ['driver_id' => $first->id])->assertOk();
    rtHeard();

    $this->postJson("/api/v1/admin/dispatch/fulfillments/{$f->id}/assign", ['driver_id' => $second->id])->assertOk();
    $drivers = collect(rtHeard()['driver'])->keyBy('driverId');

    expect($drivers->keys()->sort()->values()->all())->toBe(collect([$first->id, $second->id])->sort()->values()->all())
        ->and($drivers[$second->id]->broadcastWith())->toMatchArray(['assigned' => true, 'status' => 'assigned'])
        ->and($drivers[$first->id]->broadcastWith())->toMatchArray(['assigned' => false, 'status' => 'unassigned', 'delivery_status' => null]);
    expect(json_encode($drivers[$first->id]->broadcastWith()))->not->toContain($second->id)->not->toContain($second->name);
});

it('tells everybody when the customer cancels, asks to cancel, and the branch decides', function () {
    $w = commerceWorld();
    // Cancelled at once (nothing prepared yet).
    [$orderId, $f] = rtPlace($w);
    rtHeard();
    $this->postJson("/api/v1/orders/{$orderId}/cancel", ['reason_code' => 'changed_mind'])->assertOk();
    $heard = rtHeard();
    expect(collect($heard['customer'])->last()->broadcastWith())->toMatchArray(['status' => 'cancelled', 'fulfillment_status' => 'cancelled'])
        ->and(collect($heard['branch'])->map->broadcastWith()->pluck('status')->all())->toContain('cancelled');

    // Asked while the branch prepares it: both sides hear the request, then the decision.
    [$orderId, $f] = rtPlace($w);
    $mgr = actingAsStaff(staffWithRole('order_manager', $w->a));
    foreach (['accepted', 'preparing'] as $s) {
        $this->postJson("/api/v1/admin/fulfillments/{$f->id}/transition", ['status' => $s])->assertOk();
    }
    actingAsCustomer($w->customer);
    rtHeard();
    $this->postJson("/api/v1/orders/{$orderId}/cancel", ['reason_code' => 'changed_mind'])->assertOk()->assertJsonPath('data.status', 'requested');
    $heard = rtHeard();
    expect($heard['customer'])->toHaveCount(1)->and($heard['customer'][0]->broadcastWith()['status'])->toBe('cancellation_requested')
        ->and($heard['branch'])->toHaveCount(1)->and($heard['branch'][0]->broadcastWith())->toMatchArray(['status' => 'cancellation_requested', 'order_level' => true, 'fulfillment_status' => 'preparing']);

    actingAsStaff($mgr);
    $cancellation = Order::find($orderId)->cancellations()->firstOrFail();
    $this->postJson("/api/v1/admin/cancellations/{$cancellation->id}/decide", ['approve' => true])->assertOk();
    $heard = rtHeard();
    expect(collect($heard['customer'])->last()->broadcastWith())->toMatchArray(['status' => 'cancelled']);
});

it('sends nothing for a save that changes nothing a screen shows, and nothing when the transaction rolls back', function () {
    $this->withoutDefer();
    $w = commerceWorld();
    [$orderId, $f] = rtPlace($w);
    rtHeard();

    Order::find($orderId)->update(['customer_notes' => 'Please call first']);
    expect(rtHeard())->toBe(['customer' => [], 'branch' => [], 'driver' => []]);

    try {
        DB::transaction(function () use ($f) {
            $f->update(['status' => FS::Accepted]);
            expect(rtHeard()['customer'])->toBeEmpty();     // not while the transaction is still open
            throw new RuntimeException('the rest of the request failed');
        });
    } catch (RuntimeException) {
    }
    expect(rtHeard())->toBe(['customer' => [], 'branch' => [], 'driver' => []])->and($f->fresh()->status)->toBe(FS::Pending)
        ->and(Order::find($orderId)->progress_seq)->toBe(1);

    DB::transaction(function () use ($f) {
        $f->update(['status' => FS::Accepted]);
        expect(rtHeard()['customer'])->toBeEmpty();
    });
    expect(rtHeard()['customer'])->toHaveCount(1)->and(Order::find($orderId)->progress_seq)->toBe(2);
});

it('collapses the several saves of one request into one message with the final state', function () {
    $w = commerceWorld();
    [$orderId, $f] = rtPlace($w);
    rtHeard();

    // A courier moves a part through several steps in one go: the customer and the branch hear the end state once.
    app(OrderService::class)->advanceTo($f, FS::Ready, staffWithRole('order_manager', $w->a));
    expect(rtHeard()['customer'])->toBeEmpty();
    app(DeferredCallbackCollection::class)->invoke();
    $heard = rtHeard();
    expect($heard['customer'])->toHaveCount(1)->and($heard['customer'][0]->broadcastWith())->toMatchArray(['fulfillment_status' => 'ready', 'seq' => 2])
        ->and($heard['branch'])->toHaveCount(1)->and($heard['branch'][0]->broadcastWith())->toMatchArray(['status' => 'ready', 'stage' => 'ready']);
});

it('holds the messages back until the response is out, then sends them once', function () {
    $w = commerceWorld();
    [$orderId, $f] = rtPlace($w);
    rtHeard();

    // A service call outside a request: the message waits in the deferred queue, exactly as it does during a request.
    app(OrderService::class)->transition($f, FS::Accepted, staffWithRole('order_manager', $w->a));
    expect(rtHeard()['customer'])->toBeEmpty();

    app(DeferredCallbackCollection::class)->invoke();
    expect(rtHeard()['customer'])->toHaveCount(1);
    app(DeferredCallbackCollection::class)->invoke();
    expect(rtHeard()['customer'])->toBeEmpty();
});

it('answers the apps where the socket is, so one build works everywhere', function () {
    config(['reverb.apps.apps.0.key' => 'abc123']);
    $this->getJson('/api/v1/realtime-config')->assertOk()
        ->assertJsonPath('data.enabled', true)->assertJsonPath('data.key', 'abc123')->assertJsonPath('data.poll_seconds', 15)
        ->assertJsonPath('data.socket.path', '/app')->assertJsonPath('data.socket.scheme', 'ws')->assertJsonPath('data.socket.host', 'localhost');
    $secure = $this->getJson('https://store.example.test/api/v1/realtime-config')->assertOk();
    expect($secure->json('data.socket'))->toMatchArray(['scheme' => 'wss', 'host' => 'store.example.test', 'port' => 443]);
    config(['reverb.apps.apps.0.key' => '']);
    $this->getJson('/api/v1/realtime-config')->assertJsonPath('data.enabled', false)->assertJsonPath('data.key', null);
});

// ── Who may listen where ───────────────────────────────────────────────────────────────────────────────────────────────────

it('lets each person listen only where the messages are theirs (allow and deny matrix)', function () {
    $w = commerceWorld();
    [$orderId] = rtPlace($w);
    $users = app(BroadcastManager::class)->driver()->getChannels()['users.{id}'];
    $driverA = driverFor($w->a);
    $driverB = driverFor($w->b);
    $managerA = staffWithRole('order_manager', $w->a);
    $managerB = staffWithRole('order_manager', $w->b);
    $super = User::factory()->superAdmin()->create();
    $company = staffWithRole('admin');                          // company-wide scope
    $stranger = User::factory()->create();
    $branch = new BranchChannel;
    $driverChannel = new DriverChannel;
    $order = new OrderChannel;
    $fleet = new FleetChannel;
    $live = new LiveOrdersChannel;

    // [who, users.{own id}, users.{someone else}, branches.A, branches.B, drivers.{own}, drivers.{driver A}, orders.{this order}, fleet, live-orders]
    $matrix = [
        'customer' => [$w->customer, true, false, false, false, true, false, true, false, false],
        'stranger' => [$stranger, true, false, false, false, true, false, false, false, false],
        'driver of A' => [$driverA, true, false, false, false, true, true, false, false, false],
        'driver of B' => [$driverB, true, false, false, false, true, false, false, false, false],
        'manager of A' => [$managerA, true, false, true, false, true, false, false, false, false],
        'manager of B' => [$managerB, true, false, false, true, true, false, false, false, false],
        'company-wide staff' => [$company, true, false, false, false, true, false, false, true, true],
        'super admin' => [$super, true, false, true, true, true, false, false, true, true],
    ];
    foreach ($matrix as $who => [$user, $ownUser, $otherUser, $branchA, $branchB, $ownDriver, $driverOfA, $thisOrder, $fleetOk, $liveOk]) {
        $got = [
            'users.{own}' => $users($user, (string) $user->id), 'users.{other}' => $users($user, (string) $w->customer->id === (string) $user->id ? $stranger->id : $w->customer->id),
            'branches.A' => $branch->join($user, $w->a->id), 'branches.B' => $branch->join($user, $w->b->id),
            'drivers.{own}' => $driverChannel->join($user, (string) $user->id), 'drivers.{driver A}' => $driverChannel->join($user, $driverA->id),
            'orders.{order}' => $order->join($user, $orderId), 'fleet' => $fleet->join($user), 'live-orders' => $live->join($user),
        ];
        $want = ['users.{own}' => $ownUser, 'users.{other}' => $otherUser, 'branches.A' => $branchA, 'branches.B' => $branchB, 'drivers.{own}' => $ownDriver,
            'drivers.{driver A}' => $who === 'driver of A' ? true : $driverOfA, 'orders.{order}' => $thisOrder, 'fleet' => $fleetOk, 'live-orders' => $liveOk];
        expect($got)->toBe($want, "channel access of {$who}");
    }
});

it('registers the broadcasting auth route for the apps (bearer) and for the dashboard (session)', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => str_ends_with($r->uri(), 'broadcasting/auth'));
    $byUri = $routes->keyBy(fn ($r) => $r->uri());
    expect($byUri->keys()->sort()->values()->all())->toBe(['api/v1/broadcasting/auth', 'broadcasting/auth'])
        ->and($byUri['api/v1/broadcasting/auth']->gatherMiddleware())->toContain('auth:sanctum')
        ->and($byUri['broadcasting/auth']->gatherMiddleware())->toContain('auth');
    $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'private-users.x'])->assertUnauthorized();
});
