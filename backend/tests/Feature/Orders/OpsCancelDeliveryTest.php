<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Fulfillment\Events\DriverJobUpdated;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DeliveryProvider;
use App\Domain\Fulfillment\Models\DeliveryStatusHistory;
use App\Domain\Fulfillment\Providers\MockDeliveryProvider;
use App\Domain\Fulfillment\Services\DeliveryProviderRegistry;
use App\Domain\Fulfillment\Services\DeliveryService;
use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderCancellation;
use App\Domain\Orders\Services\OrderService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/OpsFixtures.php';
require_once __DIR__.'/AdjustmentFixtures.php';

// Bug 1: a cancelled order must not stay a live job for the driver (he would drive for goods that are no longer going anywhere).

it('cancels the open delivery with the parcel: history, audit, the driver is told live and by push', function () {
    $d = opsAssignedDelivery();
    opsPhone($d->driver, 'drv-en', 'en');
    opsPhone($d->driver, 'drv-ar', 'ar');
    $pushes = opsFakeFcm();
    Event::fake([DriverJobUpdated::class]);

    $this->postJson("/api/v1/admin/fulfillments/{$d->f->id}/transition", ['status' => 'cancelled', 'reason' => 'customer called'])->assertOk()
        ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.order_status', 'cancelled');

    $delivery = $d->delivery->fresh();
    expect($delivery->status->value)->toBe('cancelled');
    $history = DeliveryStatusHistory::where('delivery_id', $delivery->id)->orderBy('id')->get();
    expect($history->last()->to_status)->toBe('cancelled')->and($history->last()->from_status)->toBe('assigned')->and($history->last()->actor_id)->toBe($d->mgr->id)
        ->and($history->last()->note)->toContain('order cancelled');
    $audit = AuditLog::where('action', 'delivery.cancelled')->where('auditable_id', $delivery->id)->firstOrFail();
    expect($audit->actor_id)->toBe($d->mgr->id)->and($audit->new_values['status'])->toBe('cancelled')->and($audit->metadata['order'])->toBe($d->order->number);

    // The driver's live channel carries the new state of his job...
    Event::assertDispatched(DriverJobUpdated::class, fn (DriverJobUpdated $e) => $e->driverId === $d->driver->id && $e->broadcastWith()['delivery_status'] === 'cancelled' && $e->broadcastWith()['delivery_id'] === $delivery->id);
    // ...and each of his phones gets the push in its own language.
    $byToken = collect($pushes)->keyBy(0);
    expect($pushes)->toHaveCount(2)->and($byToken['drv-en'][2])->toContain($d->order->number)->toContain('cancelled')
        ->and($byToken['drv-ar'][2])->toContain($d->order->number)->toContain('تم إلغاء');
});

it('cancels the delivery of an accepted job as well, and tells nobody when no driver had it yet', function () {
    $d = opsAssignedDelivery('accepted');
    opsPhone($d->driver, 'drv', 'en');
    $pushes = opsFakeFcm();
    $this->postJson("/api/v1/admin/fulfillments/{$d->f->id}/transition", ['status' => 'cancelled'])->assertOk();
    expect($d->delivery->fresh()->status->value)->toBe('cancelled')->and($pushes)->toHaveCount(1);

    // No driver yet (waiting): the delivery is cancelled, and there is nobody to push.
    actingAsCustomer($d->w->customer);
    [$f] = readyOrder($d->w);
    $waiting = app(DeliveryService::class)->create($f, staffWithRole('order_manager', $d->w->a));
    $before = count($pushes);
    $this->postJson("/api/v1/admin/fulfillments/{$f->id}/transition", ['status' => 'cancelled'])->assertOk();
    expect($waiting->fresh()->status->value)->toBe('cancelled')->and(count($pushes))->toBe($before);
});

it('refuses every driver step on a cancelled job with a clear delivery_cancelled', function () {
    $d = opsAssignedDelivery('accepted');
    $this->postJson("/api/v1/admin/fulfillments/{$d->f->id}/transition", ['status' => 'cancelled'])->assertOk();

    actingAsStaff($d->driver);
    foreach (['accept', 'pickup', 'out-for-delivery', 'deliver'] as $step) {
        $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/{$step}")->assertStatus(409)->assertJsonPath('code', 'delivery_cancelled')
            ->assertJsonPath('message', __('ops_integrity.errors.delivery_cancelled'));
    }
    $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/fail", ['reason_code' => 'customer_absent'])->assertStatus(409)->assertJsonPath('code', 'delivery_cancelled');
    expect($d->f->fresh()->status->value)->toBe('cancelled')->and($d->delivery->fresh()->picked_up_at)->toBeNull();
});

it('refuses pick-up, out-for-delivery and delivery when only the parcel says cancelled (a job that was left live by older code)', function () {
    $d = opsAssignedDelivery('accepted');
    // The state the bug left behind: the parcel is cancelled, the delivery still looks live to the driver.
    $d->f->forceFill(['status' => 'cancelled'])->save();
    expect($d->delivery->fresh()->status->value)->toBe('accepted');

    actingAsStaff($d->driver);
    foreach (['pickup', 'out-for-delivery'] as $step) {
        $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/{$step}")->assertStatus(409)->assertJsonPath('code', 'delivery_cancelled');
    }
    $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/deliver", ['proof' => UploadedFile::fake()->image('door.jpg')])->assertStatus(409)->assertJsonPath('code', 'delivery_cancelled');
    expect($d->delivery->fresh()->status->value)->toBe('accepted')->and($d->order->fresh()->payment_status->value)->toBe('pending');
});

it('cancels the delivery when a manager approves the customer\'s cancellation request of a ready order', function () {
    $d = opsAssignedDelivery();
    actingAsCustomer($d->w->customer);
    $this->postJson("/api/v1/orders/{$d->order->id}/cancel", ['reason_code' => 'changed_mind'])->assertOk()->assertJsonPath('data.status', 'requested');
    // While the request waits the delivery is still live (nothing is decided) ...
    expect($d->delivery->fresh()->status->value)->toBe('assigned');

    actingAsStaff($d->mgr);
    $request = OrderCancellation::where('order_id', $d->order->id)->firstOrFail();
    $this->postJson("/api/v1/admin/cancellations/{$request->id}/decide", ['approve' => true])->assertOk();
    expect($d->delivery->fresh()->status->value)->toBe('cancelled')->and($d->order->fresh()->status->value)->toBe('cancelled');
});

it('cancels the delivery of a staff cancellation of the whole order too, and a repeat does nothing more', function () {
    $d = opsAssignedDelivery();
    $pushes = opsFakeFcm();
    opsPhone($d->driver, 'drv', 'en');
    $this->postJson("/api/v1/admin/orders/{$d->order->id}/cancel", ['reason_code' => 'other', 'reason' => 'duplicate'])->assertOk();
    expect($d->delivery->fresh()->status->value)->toBe('cancelled')->and($pushes)->toHaveCount(1);

    $this->postJson("/api/v1/admin/orders/{$d->order->id}/cancel", ['reason_code' => 'other', 'reason' => 'duplicate'])->assertOk();
    expect($pushes)->toHaveCount(1)->and(AuditLog::where('action', 'delivery.cancelled')->count())->toBe(1)
        ->and(DeliveryStatusHistory::where('delivery_id', $d->delivery->id)->where('to_status', 'cancelled')->count())->toBe(1);
});

it('leaves a delivery that is already on the road alone when its part is cancelled by the state machine (not allowed once dispatched)', function () {
    $d = opsAssignedDelivery('accepted');
    actingAsStaff($d->driver);
    $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/pickup")->assertOk();
    actingAsStaff($d->mgr);
    $this->postJson("/api/v1/admin/fulfillments/{$d->f->id}/transition", ['status' => 'cancelled'])->assertStatus(409)->assertJsonPath('code', 'invalid_state_transition');
    expect($d->delivery->fresh()->status->value)->toBe('picked_up');
});

it('keeps another branch\'s delivery of a split order when only one part is cancelled', function () {
    $s = opsSplitOrder();
    $driverA = driverFor($s->w->a);
    $driverB = driverFor($s->w->b);
    foreach ([[$s->fa, $s->mgrA, $driverA], [$s->fb, $s->mgrB, $driverB]] as [$f, $mgr, $driver]) {
        opsWalk($f, [FS::Accepted, FS::Preparing, FS::Ready]);
        actingAsStaff($mgr);
        $this->postJson("/api/v1/admin/dispatch/fulfillments/{$f->id}/assign", ['driver_id' => $driver->id])->assertOk();
    }
    $this->postJson("/api/v1/admin/fulfillments/{$s->fb->id}/transition", ['status' => 'cancelled'])->assertOk();

    expect(Delivery::where('order_fulfillment_id', $s->fb->id)->firstOrFail()->status->value)->toBe('cancelled')
        ->and(Delivery::where('order_fulfillment_id', $s->fa->id)->firstOrFail()->status->value)->toBe('assigned')
        ->and($s->order->fresh()->status->value)->toBe('processing');
});

it('cancels the delivery when an item adjustment takes the last item out of the parcel', function () {
    $a = adjustmentWorld(['qty' => [1, 0]]); // one sofa, being prepared, branch A manager signed in
    $delivery = app(DeliveryService::class)->create($a->f, $a->mgr);
    $driver = driverFor($a->w->a);
    app(DeliveryService::class)->assignDriver($delivery, $driver, $a->mgr);
    opsPhone($driver, 'drv', 'en');
    $pushes = opsFakeFcm();

    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'remove']])->assertOk()->assertJsonPath('data.order_cancelled', true);
    expect($delivery->fresh()->status->value)->toBe('cancelled')->and($pushes)->toHaveCount(1)
        ->and(AuditLog::where('action', 'delivery.cancelled')->where('auditable_id', $delivery->id)->count())->toBe(1);
});

it('is safe to call the service twice for the same cancelled parcel', function () {
    $d = opsAssignedDelivery();
    $f = $d->f->fresh();
    app(OrderService::class)->transition($f, FS::Cancelled, $d->mgr, 'x');
    app(DeliveryService::class)->cancelForFulfillment($f->fresh(), $d->mgr, 'x');
    expect(AuditLog::where('action', 'delivery.cancelled')->count())->toBe(1);
});

/** A courier whose "cancel booking" is recorded, and can be made to fail. */
class OpsFlakyCourier extends MockDeliveryProvider
{
    public static array $cancelled = [];

    public static bool $fail = false;

    public function cancelBooking(Delivery $delivery): bool
    {
        if (self::$fail) {
            throw new RuntimeException('courier is down');
        }
        self::$cancelled[] = $delivery->provider_reference;

        return true;
    }
}

it('releases a courier booking after the commit, and a courier that is down never blocks the cancellation', function (bool $courierDown) {
    OpsFlakyCourier::$cancelled = [];
    OpsFlakyCourier::$fail = $courierDown;
    app()->singleton(DeliveryProviderRegistry::class); // one registry for the whole request, so the courier registered below is found
    app(DeliveryProviderRegistry::class)->register('flaky', OpsFlakyCourier::class);
    $provider = DeliveryProvider::create(['code' => 'flaky', 'name' => 'Flaky Express', 'driver' => 'flaky', 'is_active' => true, 'webhook_secret' => 'x', 'settings' => []]);
    $w = commerceWorld();
    [$f, $mgr] = readyOrder($w);
    $delivery = app(DeliveryService::class)->create($f, $mgr, 'provider', $provider->id);
    expect($delivery->status->value)->toBe('accepted')->and($delivery->provider_reference)->toStartWith('MOCK-');

    $this->postJson("/api/v1/admin/fulfillments/{$f->id}/transition", ['status' => 'cancelled'])->assertOk()->assertJsonPath('data.order_status', 'cancelled');
    $delivery = $delivery->fresh();
    expect($delivery->status->value)->toBe('cancelled');
    if ($courierDown) {
        expect(OpsFlakyCourier::$cancelled)->toBe([])->and($delivery->last_error)->toContain('call the courier');
    } else {
        expect(OpsFlakyCourier::$cancelled)->toBe([$delivery->provider_reference])->and($delivery->last_error)->toBeNull();
    }
})->with([[false], [true]]);
