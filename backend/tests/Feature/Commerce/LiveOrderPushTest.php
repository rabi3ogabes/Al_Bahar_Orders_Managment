<?php

use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Fulfillment\Enums\DeliveryStatus as DS;
use App\Domain\Fulfillment\Events\DispatchUpdated;
use App\Domain\Fulfillment\Events\OrderDriverMoved;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserDevice;
use App\Domain\Notifications\Jobs\RefreshOrderProgressJob;
use App\Domain\Notifications\Services\PushNotifier;
use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Enums\OrderStatus as OS;
use App\Domain\Orders\Events\OrderPlaced;
use App\Domain\Orders\Events\OrderUpdated;
use App\Domain\Orders\Models\Order;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/LiveOrderHelpers.php';

/*
 * Pushing the live order card: when, to whom, in which language and in what FCM shape. Clock frozen at Sat 14 Nov 2026 18:00 Qatar.
 * Orders are placed BEFORE the customer's phone is registered, so placing one does not itself send (the queue runs inline in tests);
 * each test then asks for the push it wants to look at.
 */

beforeEach(function () {
    lopFreeze();
    $this->w = commerceWorld();
    $this->fcm = lopFcm();
    $this->push = fn (Order $o) => app(PushNotifier::class)->refreshOrder($o->id);
    $this->device = fn (string $token, string $locale, string $platform = 'android') => UserDevice::create(['user_id' => $this->w->customer->id, 'platform' => $platform, 'push_token' => $token, 'locale' => $locale]);
});

// ── The FCM message ──────────────────────────────────────────────────────────────────────────────────────────────────

it('sends Android a data-only high-priority message that collapses per order and expires after 4 hours', function () {
    $order = lopMove(lopPlace($this->w), FS::Preparing);
    ($this->device)('tok-android', 'en');
    ($this->push)($order);

    $m = lopSent()[0];
    expect($m['token'])->toBe('tok-android')
        ->and($m)->not->toHaveKey('notification')                               // no notification block: the app draws the card itself
        ->and($m['android'])->toBe(['priority' => 'high', 'collapse_key' => 'order-'.$order->id, 'ttl' => '14400s'])
        ->and($m['data'])->toMatchArray(['kind' => 'order_progress', 'order_id' => $order->id, 'number' => $order->number, 'type' => 'delivery', 'step' => '1', 'total' => '4',
            'phase' => 'preparing', 'title' => 'Arrives at 6:35pm-6:55pm', 'eta_from' => '2026-11-14T15:35:00Z', 'eta_to' => '2026-11-14T15:55:00Z', 'done' => '0', 'deep_link' => '/orders/'.$order->id])
        ->and($m['data']['brand'])->toBe('{"primary":"#8A1538","accent":"#B3254F"}')
        ->and($m['data']['seq'])->toBe((string) (now()->getTimestamp() * 1000))
        ->and(array_filter($m['data'], fn ($v) => ! is_string($v)))->toBe([])  // FCM data is strings only
        ->and($m['data'])->not->toHaveKeys(['driver_name', 'driver_phone', 'vehicle']); // null values are left out, not sent as ""
});

it('sends iOS an alert in one thread, collapsed per order, expiring after 4 hours', function () {
    $order = lopMove(lopPlace($this->w), FS::Preparing);
    ($this->device)('tok-ios', 'en', 'ios');
    ($this->push)($order);

    $apns = lopSent()[0]['apns'];
    expect($apns['headers'])->toBe(['apns-push-type' => 'alert', 'apns-priority' => '10', 'apns-collapse-id' => 'order-'.$order->id, 'apns-expiration' => (string) (now()->getTimestamp() + 14400)])
        ->and($apns['payload']['aps'])->toBe(['alert' => ['title' => 'Arrives at 6:35pm-6:55pm', 'body' => 'Being prepared at '.$order->fulfillments->first()->branch->name_en.'.'],
            'thread-id' => 'order-'.$order->id, 'interruption-level' => 'active', 'sound' => 'default']);
});

it('writes each device\'s message in its own language', function () {
    $order = lopMove(lopPlace($this->w), FS::Preparing);
    ($this->device)('tok-en', 'en');
    ($this->device)('tok-ar', 'ar');
    ($this->device)('tok-qa', 'ar_QA');
    ($this->device)('tok-none', '');
    ($this->push)($order);

    $titles = collect(lopSent())->pluck('data.title', 'token');
    expect($titles->all())->toBe(['tok-en' => 'Arrives at 6:35pm-6:55pm', 'tok-ar' => 'تصل بين 6:35 م و 6:55 م', 'tok-qa' => 'تصل بين 6:35 م و 6:55 م', 'tok-none' => 'Arrives at 6:35pm-6:55pm'])
        ->and(collect(lopSent())->firstWhere('token', 'tok-ar')['apns']['payload']['aps']['alert']['body'])->toStartWith('قيد التجهيز في');
});

it('puts the driver\'s first name and phone in the message once a driver is on the job', function () {
    $driver = driverFor($this->w->a);
    $driver->update(['name' => 'Ahmed Al Marri', 'phone' => '+97455123456']);
    $order = lopMove(lopPlace($this->w), FS::Ready, delivery: DS::Accepted, driver: $driver);
    ($this->device)('tok', 'en');
    ($this->push)($order);

    expect(lopSent()[0]['data'])->toMatchArray(['driver_name' => 'Ahmed', 'driver_phone' => '+97455123456', 'detail' => 'driver_accepted']);
});

it('only reaches the customer who placed the order', function () {
    $order = lopPlace($this->w);
    ($this->device)('tok-mine', 'en');
    UserDevice::create(['user_id' => User::factory()->create()->id, 'platform' => 'android', 'push_token' => 'tok-other', 'locale' => 'en']);
    ($this->push)($order);

    expect(collect(lopSent())->pluck('token')->all())->toBe(['tok-mine']);
});

it('skips devices that were switched off and retires a token Firebase no longer knows', function () {
    $order = lopPlace($this->w);
    ($this->device)('tok-off', 'en')->update(['disabled_at' => now()]);
    $gone = ($this->device)('tok-gone', 'en');
    $this->fcm['mode'] = 'gone';
    ($this->push)($order);

    expect(collect(lopSent())->pluck('token')->all())->toBe(['tok-gone'])->and($gone->fresh()->disabled_at)->not->toBeNull();
});

it('does nothing, and calls nobody, when Firebase is not set up', function () {
    $order = lopPlace($this->w);
    ($this->device)('tok', 'en');
    config(['services.fcm.project_id' => null, 'services.fcm.credentials_path' => null]);
    Http::fake();
    ($this->push)($order);

    Http::assertNothingSent();
});

// ── When it sends ────────────────────────────────────────────────────────────────────────────────────────────────────

it('sends on every real change of stage and says nothing when nothing changed', function () {
    $order = lopPlace($this->w);
    $driver = driverFor($this->w->a);
    ($this->device)('tok', 'en');
    $delivery = fn (DS $to) => Delivery::where('driver_id', $driver->id)->update(['status' => $to->value]);
    $sent = [];
    $step = function (string $label, callable $change) use (&$sent, $order) {
        $before = count(lopSent());
        $change();
        ($this->push)($order);
        $new = array_slice(lopSent(), $before);
        $sent[$label] = $new === [] ? null : $new[0]['data']['detail'];
    };

    $step('placed', fn () => null);
    $step('placed again', fn () => null);                                   // same state: nothing
    $step('accepted', fn () => lopMove($order, FS::Accepted));
    $step('preparing', fn () => lopMove($order, FS::Preparing));            // same card as "accepted": nothing
    $step('ready', fn () => lopMove($order, FS::Ready));                    // still preparing: nothing
    $step('driver assigned', fn () => lopMove($order, FS::Ready, delivery: DS::Assigned, driver: $driver));
    $step('driver accepted', fn () => $delivery(DS::Accepted));
    $step('picked up', function () use ($order, $delivery) {
        lopMove($order, FS::Dispatched);
        $delivery(DS::PickedUp);
    });
    $step('out for delivery', function () use ($order, $delivery) {
        lopMove($order, FS::OutForDelivery);
        $delivery(DS::OutForDelivery);
    });
    $step('delivered', function () use ($order, $delivery) {
        lopMove($order, FS::Delivered);
        $delivery(DS::Delivered);
    });

    expect($sent)->toBe(['placed' => 'placed', 'placed again' => null, 'accepted' => 'preparing', 'preparing' => null, 'ready' => null,
        'driver assigned' => 'driver_assigned', 'driver accepted' => 'driver_accepted', 'picked up' => 'picked_up', 'out for delivery' => 'out_for_delivery', 'delivered' => 'delivered']);
    expect(lopSent()[array_key_last(lopSent())]['data'])->toMatchArray(['done' => '1', 'step' => '3']);
});

it('sends the cancelled and failed endings', function () {
    $cancelled = lopPlace($this->w);
    $failed = lopMove(lopPlace($this->w), FS::Failed, delivery: DS::Failed, driver: driverFor($this->w->a));
    ($this->device)('tok', 'en');
    ($this->push)($cancelled);
    $cancelled->forceFill(['status' => OS::Cancelled->value, 'cancelled_at' => now()])->save();
    ($this->push)($cancelled);
    ($this->push)($failed);

    expect(collect(lopSent())->pluck('data.phase')->all())->toBe(['placed', 'cancelled', 'failed'])
        ->and(lopSent()[1]['data'])->toMatchArray(['done' => '1', 'title' => 'Order cancelled'])
        ->and(lopSent()[2]['data'])->toMatchArray(['done' => '1', 'title' => 'Delivery unsuccessful']);
});

it('pushes again, quietly, when the arrival window moves by 5 minutes or more, and not for less', function () {
    $order = lopMove(lopPlace($this->w), FS::Preparing);
    ($this->device)('tok-android', 'en');
    ($this->device)('tok-ios', 'en', 'ios');
    ($this->push)($order);
    expect(lopSent())->toHaveCount(2);                                         // window 18:35-18:55

    $this->travelTo(now()->addMinutes(3));                                       // 18:03: the window has not moved
    ($this->push)($order);
    expect(lopSent())->toHaveCount(2);

    $this->travelTo(now()->addMinutes(30));                                      // 18:33: past the 25 minutes of preparation, so the window slides from 18:35-18:55 to 18:45-19:00
    ($this->push)($order);
    $sent = array_slice(lopSent(), 2);
    expect($sent)->toHaveCount(2)
        ->and($sent[0]['data'])->toMatchArray(['quiet' => '1', 'eta_to' => '2026-11-14T16:00:00Z'])
        ->and($sent[1]['apns']['payload']['aps'])->toMatchArray(['interruption-level' => 'passive'])->not->toHaveKey('sound')
        ->and($sent[1]['apns']['headers']['apns-priority'])->toBe('5');

    ($this->push)($order);                                                       // the same minute again: nothing new
    expect(lopSent())->toHaveCount(4);
});

it('keeps quiet for a window that moved less than the limit', function () {
    config(['platform.live_order.shift_minutes' => 10]);
    $order = lopMove(lopPlace($this->w), FS::Preparing);
    ($this->device)('tok', 'en');
    ($this->push)($order);                                                       // window end 18:55

    $this->travelTo(now()->addMinutes(33));                                      // 18:33: end 19:00, only 5 minutes later
    ($this->push)($order);
    expect(lopSent())->toHaveCount(1);

    $this->travelTo(now()->addMinutes(12));                                      // 18:45: end 19:15, 20 minutes later
    ($this->push)($order);
    expect(lopSent())->toHaveCount(2)->and(lopSent()[1]['data'])->toMatchArray(['quiet' => '1', 'eta_to' => '2026-11-14T16:15:00Z']);
});

it('treats a change of stage as a normal alert, even when the window moved too', function () {
    $order = lopMove(lopPlace($this->w), FS::Preparing);
    ($this->device)('tok', 'en', 'ios');
    ($this->push)($order);
    $this->travelTo(now()->addMinutes(40));
    lopMove($order, FS::Dispatched, delivery: DS::PickedUp, driver: driverFor($this->w->a));
    ($this->push)($order);

    $last = lopSent()[1];
    expect($last['data'])->not->toHaveKey('quiet')->and($last['apns']['payload']['aps']['interruption-level'])->toBe('active');
});

it('stops updating an order 30 minutes after it is done', function () {
    $order = lopMove(lopPlace($this->w), FS::Delivered, ['delivered_at' => now()], DS::Delivered, driverFor($this->w->a));
    $order->forceFill(['status' => OS::Completed->value, 'completed_at' => now()])->save();
    ($this->device)('tok', 'en');
    $this->travelTo(now()->addMinutes(20));
    ($this->push)($order);
    expect(lopSent())->toHaveCount(1);                                           // still inside the 30 minutes

    Cache::flush();                                                              // as if nothing were remembered about what was sent
    $this->travelTo(now()->addMinutes(15));
    ($this->push)($order);
    expect(lopSent())->toHaveCount(1);                                           // 35 minutes after delivery: silent
});

it('never throws into the order flow and sends the card again at the next try when Firebase fails', function () {
    $order = lopMove(lopPlace($this->w), FS::Preparing);
    ($this->device)('tok', 'en');
    $this->fcm['mode'] = 'down';
    ($this->push)($order);                                                       // swallowed
    expect(lopSent())->toHaveCount(0);

    $this->fcm['mode'] = 'error';
    ($this->push)($order);                                                       // Firebase answered 500: reached, but not delivered
    expect(lopSent())->toHaveCount(1);

    $this->fcm['mode'] = 'ok';
    ($this->push)($order);                                                       // recovered: the same card goes out again
    expect(lopSent())->toHaveCount(2)->and(lopSent()[1]['data']['order_id'])->toBe($order->id);

    ($this->push)($order);                                                       // delivered once: nothing more
    expect(lopSent())->toHaveCount(2);
});

it('keeps one unreachable device from stopping the others', function () {
    $order = lopPlace($this->w);
    ($this->device)('tok-bad', 'en');
    ($this->device)('tok-good', 'en');
    $this->fcm['bad_token'] = 'tok-bad';
    ($this->push)($order);

    expect(collect(lopSent())->pluck('token')->all())->toBe(['tok-good']);
});

// ── What triggers it ─────────────────────────────────────────────────────────────────────────────────────────────────

it('follows an order update event from the live sockets', function () {
    $order = lopMove(lopPlace($this->w), FS::Preparing);
    ($this->device)('tok', 'en');
    app(PushNotifier::class)->orderUpdated(new OrderUpdated($this->w->customer->id, $order->id, $order->number, 'processing'));

    expect(lopSent())->toHaveCount(1)->and(lopSent()[0]['data']['order_id'])->toBe($order->id);
});

it('follows a branch moving its part of the order, even when the order status did not change', function () {
    $order = lopMove(lopPlace($this->w), FS::Accepted);
    ($this->device)('tok', 'en');
    $f = $order->fulfillments->first();
    app(PushNotifier::class)->dispatchUpdated(new DispatchUpdated($f->branch_id, $order->number, 'order', 'accepted', null, $f->id));

    expect(lopSent())->toHaveCount(1)->and(lopSent()[0]['data'])->toMatchArray(['phase' => 'preparing']);
});

it('still tells the driver and the branch what it told them before', function () {
    $driver = driverFor($this->w->a);
    $mgr = staffWithRole('order_manager', $this->w->a);
    BranchEmployee::firstOrCreate(['branch_id' => $this->w->a->id, 'user_id' => $mgr->id], ['is_active' => true]);
    UserDevice::create(['user_id' => $driver->id, 'platform' => 'android', 'push_token' => 'drv', 'locale' => 'en']);
    UserDevice::create(['user_id' => $mgr->id, 'platform' => 'android', 'push_token' => 'mgr', 'locale' => 'ar']);
    $n = app(PushNotifier::class);
    $n->dispatchUpdated(new DispatchUpdated($this->w->a->id, 'AB7', 'delivery', 'assigned', $driver->id));
    $n->dispatchUpdated(new DispatchUpdated($this->w->a->id, 'AB7', 'order', 'ready'));

    $bodies = collect(lopSent(all: true))->groupBy('token')->map(fn ($rows) => $rows->pluck('notification.body')->all());
    expect($bodies['drv'])->toContain('New delivery: order AB7. Open the app to accept it.')
        ->and($bodies['mgr'])->toBe(['الطلب AB7 جاهز وبانتظار سائق.']);
});

it('queues one small job when an order is placed', function () {
    Queue::fake();
    event(new OrderPlaced('0199a6d8-0000-7000-8000-000000000001'));

    Queue::assertPushed(RefreshOrderProgressJob::class, fn (RefreshOrderProgressJob $j) => $j->orderId === '0199a6d8-0000-7000-8000-000000000001' && $j->queue === 'notifications');
});

it('re-checks the arrival window on driver position ticks at most every two minutes per order', function () {
    Queue::fake();
    $tick = fn (string $orderId) => event(new OrderDriverMoved($orderId, 'Ahmed', 25.3, 51.5, 'out_for_delivery'));
    $a = '0199a6d8-0000-7000-8000-00000000000a';
    $b = '0199a6d8-0000-7000-8000-00000000000b';

    $tick($a);
    $tick($a);
    $tick($b);
    Queue::assertPushed(RefreshOrderProgressJob::class, 2);                      // one for each order, not one per tick

    $this->travelTo(now()->addSeconds(119));
    $tick($a);
    Queue::assertPushed(RefreshOrderProgressJob::class, 2);
    $this->travelTo(now()->addSeconds(2));
    $tick($a);
    Queue::assertPushed(RefreshOrderProgressJob::class, 3);
});

it('lets a second copy of the job for the same order be dropped while the first waits', function () {
    $job = new RefreshOrderProgressJob('0199a6d8-0000-7000-8000-00000000000c');

    expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)->and($job->uniqueId())->toBe('0199a6d8-0000-7000-8000-00000000000c')->and($job->uniqueFor)->toBe(30);
});

it('runs the whole journey through the real order events, one card per change', function () {
    $driver = driverFor($this->w->a);
    ($this->device)('tok', 'en');
    $order = lopPlace($this->w);                                                 // OrderPlaced → job → push (the queue runs inline in tests)
    actingAsStaff(staffWithRole('order_manager', $this->w->a));
    $f = $order->fulfillments->first();
    foreach (['accepted', 'preparing', 'ready'] as $to) {
        $this->postJson("/api/v1/admin/fulfillments/{$f->id}/transition", ['status' => $to])->assertOk();
    }
    $this->postJson("/api/v1/admin/dispatch/fulfillments/{$f->id}/assign", ['driver_id' => $driver->id])->assertOk();
    actingAsStaff($driver);
    $delivery = Delivery::where('order_fulfillment_id', $f->id)->firstOrFail();
    foreach (['accept', 'pickup', 'out-for-delivery'] as $action) {
        $this->postJson("/api/v1/admin/deliveries/{$delivery->id}/{$action}")->assertOk();
    }

    expect(collect(lopSent())->pluck('data.detail')->all())->toBe(['placed', 'preparing', 'driver_assigned', 'driver_accepted', 'picked_up', 'out_for_delivery']);
});
