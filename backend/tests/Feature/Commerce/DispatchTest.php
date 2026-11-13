<?php

use App\Broadcasting\BranchChannel;
use App\Broadcasting\FleetChannel;
use App\Broadcasting\OrderChannel;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Fulfillment\Events\DriverMoved;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DriverLocation;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

it('auto mode hands a ready delivery to the least busy on-duty driver', function () {
    $w = commerceWorld();
    $w->a->update(['dispatch_mode' => 'auto']);
    $busy = driverFor($w->a);
    $free = driverFor($w->a);
    $off = driverFor($w->a, onDuty: false);
    // The busy driver already has an open job.
    $busyF = readyOrder($w)[0];
    $first = Delivery::where('order_fulfillment_id', $busyF->id)->firstOrFail();
    expect($first->driver_id)->not->toBeNull()->and($first->status->value)->toBe('assigned');
    $firstDriver = $first->driver_id;

    actingAsCustomer($w->customer);
    [$f2] = readyOrder($w);
    $second = Delivery::where('order_fulfillment_id', $f2->id)->firstOrFail();
    expect($second->driver_id)->not->toBe($firstDriver)->and($second->driver_id)->not->toBe($off->id)
        ->and(in_array($second->driver_id, [$busy->id, $free->id], true))->toBeTrue();
});

it('keeps the delivery pending when nobody is on duty and hands it over when a driver starts duty', function () {
    $w = commerceWorld();
    $w->a->update(['dispatch_mode' => 'auto']);
    $driver = driverFor($w->a, onDuty: false);
    [$f] = readyOrder($w);
    $d = Delivery::where('order_fulfillment_id', $f->id)->firstOrFail();
    expect($d->status->value)->toBe('pending')->and($d->driver_id)->toBeNull();

    actingAsStaff($driver);
    $this->putJson('/api/v1/admin/dispatch/driver/duty', ['on_duty' => true])->assertOk()->assertJsonPath('data.on_duty', true);
    expect($d->fresh()->driver_id)->toBe($driver->id)->and($d->fresh()->status->value)->toBe('assigned');
    $jobs = $this->getJson('/api/v1/admin/dispatch/driver/jobs')->assertOk()->json('data');
    expect($jobs)->toHaveCount(1)->and($jobs[0]['status'])->toBe('assigned');
});

it('manual mode leaves assignment to a branch employee, who sees the drivers and their load', function () {
    $w = commerceWorld();
    $driver = driverFor($w->a);
    [$f, $mgr] = readyOrder($w);
    expect(Delivery::where('order_fulfillment_id', $f->id)->exists())->toBeFalse();

    $this->getJson('/api/v1/admin/dispatch/overview?branch_id='.$w->a->id)->assertOk()
        ->assertJsonPath('data.ready', 1)->assertJsonPath('data.waiting_for_driver', 1)->assertJsonPath('data.drivers_on_duty', 1)->assertJsonPath('data.branch.dispatch_mode', 'manual');
    expect($this->getJson('/api/v1/admin/dispatch/drivers?branch_id='.$w->a->id)->json('data.0.id'))->toBe($driver->id);
    expect($this->getJson('/api/v1/admin/dispatch/queue?stage=ready&branch_id='.$w->a->id)->json('data.0.fulfillment_id'))->toBe($f->id);

    $this->postJson("/api/v1/admin/dispatch/fulfillments/{$f->id}/assign", ['driver_id' => $driver->id])->assertOk()->assertJsonPath('data.status', 'assigned');
    actingAsStaff($driver);
    $job = $this->getJson('/api/v1/admin/dispatch/driver/jobs')->json('data.0');
    expect($job['id'])->toBe(Delivery::first()->id);
    $this->postJson("/api/v1/admin/deliveries/{$job['id']}/accept")->assertOk()->assertJsonPath('data.status', 'accepted');
    $this->postJson("/api/v1/admin/deliveries/{$job['id']}/pickup")->assertOk()->assertJsonPath('data.status', 'picked_up');
});

it('keeps a failed delivery in the branch queue so staff can bring it back', function () {
    $w = commerceWorld();
    $driver = driverFor($w->a);
    [$f, $mgr] = readyOrder($w);
    $this->postJson("/api/v1/admin/dispatch/fulfillments/{$f->id}/assign", ['driver_id' => $driver->id])->assertOk();
    $delivery = Delivery::first();
    actingAsStaff($driver);
    foreach (['accept', 'pickup', 'out-for-delivery'] as $step) {
        $this->postJson("/api/v1/admin/deliveries/{$delivery->id}/{$step}")->assertOk();
    }
    $this->postJson("/api/v1/admin/deliveries/{$delivery->id}/fail", ['reason_code' => 'customer_absent'])->assertOk();

    actingAsStaff($mgr);
    $this->getJson('/api/v1/admin/dispatch/overview?branch_id='.$w->a->id)->assertOk()->assertJsonPath('data.failed', 1)->assertJsonPath('data.out_for_delivery', 0);
    $row = $this->getJson('/api/v1/admin/dispatch/queue?stage=failed&branch_id='.$w->a->id)->assertOk()->json('data.0');
    expect($row['fulfillment_id'])->toBe($f->id)->and($row['status'])->toBe('failed')->and($row['delivery']['id'])->toBe($delivery->id)->and($row['delivery']['status'])->toBe('failed');
    expect($this->getJson('/api/v1/admin/dispatch/queue?stage=out&branch_id='.$w->a->id)->json('data'))->toBe([]);
    $this->postJson("/api/v1/admin/deliveries/{$delivery->id}/return")->assertOk()->assertJsonPath('data.status', 'returned');
    expect($this->getJson('/api/v1/admin/dispatch/queue?stage=failed&branch_id='.$w->a->id)->json('data'))->toBe([]);
});

it('tells the driver and the branch staff what is in the parcel, without prices', function () {
    $w = commerceWorld();
    $driver = driverFor($w->a);
    [$f, $mgr] = readyOrder($w);
    $line = $f->items()->firstOrFail();

    $row = $this->getJson('/api/v1/admin/dispatch/queue?stage=ready&branch_id='.$w->a->id)->assertOk()->json('data.0');
    expect($row['items'])->toHaveCount($f->items()->count())
        ->and($row['items'][0])->toHaveKeys(['id', 'name', 'variant_name', 'sku', 'quantity'])->not->toHaveKeys(['unit_price', 'line_total'])
        ->and($row['items_count'])->toBe(array_sum(array_column($row['items'], 'quantity')));

    $this->postJson("/api/v1/admin/dispatch/fulfillments/{$f->id}/assign", ['driver_id' => $driver->id])->assertOk();
    actingAsStaff($driver);
    $job = $this->getJson('/api/v1/admin/dispatch/driver/jobs')->assertOk()->json('data.0');
    expect($job['items'])->toHaveCount(count($row['items']))->and($job['items'][0]['name'])->toBe($row['items'][0]['name']);

    // A cancelled unit is no longer part of the parcel.
    $line->forceFill(['quantity_cancelled' => $line->quantity])->save();
    $again = $this->getJson('/api/v1/admin/dispatch/driver/jobs')->assertOk()->json('data.0.items');
    expect(collect($again)->pluck('id'))->not->toContain($line->id);
});

it('rejects assigning someone who is not a driver of that branch', function () {
    $w = commerceWorld();
    $otherBranchDriver = driverFor($w->b);
    [$f] = readyOrder($w);
    $this->postJson("/api/v1/admin/dispatch/fulfillments/{$f->id}/assign", ['driver_id' => $otherBranchDriver->id])->assertStatus(422)->assertJsonPath('code', 'driver_not_eligible');
});

it('lets one driver work for several branches and refuses non-drivers on the driver endpoints', function () {
    $w = commerceWorld();
    $multi = driverFor([$w->a, $w->b]);
    actingAsStaff($multi);
    expect($this->getJson('/api/v1/admin/dispatch/driver')->assertOk()->json('data.branches'))->toHaveCount(2);

    actingAsStaff(staffWithRole('order_manager', $w->a));
    $this->getJson('/api/v1/admin/dispatch/driver')->assertStatus(403);
});

it('keeps staff of one branch out of another branch', function () {
    $w = commerceWorld();
    actingAsStaff(staffWithRole('order_manager', $w->a));
    $this->getJson('/api/v1/admin/dispatch/overview?branch_id='.$w->b->id)->assertStatus(403);
});

it('lists a branch driver who has never gone on duty (no profile row yet)', function () {
    $w = commerceWorld();
    $driver = staffWithRole('delivery_employee', null);
    BranchEmployee::create(['branch_id' => $w->a->id, 'user_id' => $driver->id, 'is_active' => true, 'is_delivery_driver' => true]);
    actingAsStaff(staffWithRole('order_manager', $w->a));

    $this->getJson('/api/v1/admin/dispatch/overview?branch_id='.$w->a->id)->assertOk()->assertJsonPath('data.drivers_total', 1)->assertJsonPath('data.drivers_on_duty', 0);
    $this->getJson('/api/v1/admin/dispatch/drivers?branch_id='.$w->a->id)->assertOk()->assertJsonPath('data.0.on_duty', false)->assertJsonPath('data.0.open_jobs', 0);
    actingAsStaff($driver);
    $this->getJson('/api/v1/admin/dispatch/driver')->assertOk()->assertJsonPath('data.on_duty', false);
});

it('tracks the driver live: breadcrumbs, throttled broadcast, customer tracking and the staff fleet', function () {
    $w = commerceWorld();
    $driver = driverFor($w->a);
    [$f, $mgr] = readyOrder($w);
    $this->postJson("/api/v1/admin/dispatch/fulfillments/{$f->id}/assign", ['driver_id' => $driver->id])->assertOk();
    $orderId = $f->order_id;
    $w->address->update(['latitude' => 25.30, 'longitude' => 51.50]);
    Order::find($orderId)->update(['address_snapshot' => $w->address->fresh()->snapshot()]);

    $this->actingAs($w->customer, 'sanctum');
    $this->getJson("/api/v1/orders/{$orderId}/tracking")->assertOk()->assertJsonPath('data.tracking', false)->assertJsonPath('data.driver', null);

    actingAsStaff($driver);
    $job = $this->getJson('/api/v1/admin/dispatch/driver/jobs')->json('data.0');
    $this->postJson("/api/v1/admin/deliveries/{$job['id']}/accept")->assertOk();
    $this->postJson("/api/v1/admin/deliveries/{$job['id']}/pickup")->assertOk();

    Event::fake([DriverMoved::class]);
    Cache::flush();
    $this->postJson('/api/v1/admin/dispatch/driver/location', ['latitude' => 25.28, 'longitude' => 51.52, 'speed_kmh' => 32.5, 'heading' => 90])->assertOk();
    $this->postJson('/api/v1/admin/dispatch/driver/location', ['latitude' => 25.281, 'longitude' => 51.521])->assertOk(); // inside the 3 s window
    Event::assertDispatchedTimes(DriverMoved::class, 1);
    Event::assertDispatched(DriverMoved::class, fn ($e) => $e->orderId === $orderId && $e->lat === 25.28);
    expect(DriverLocation::count())->toBe(1)->and(DriverLocation::first()->delivery_id)->toBe($job['id']);

    $this->actingAs($w->customer, 'sanctum');
    $t = $this->getJson("/api/v1/orders/{$orderId}/tracking")->assertOk()->assertJsonPath('data.tracking', true)->json('data');
    expect($t['driver']['lat'])->toBe(25.281)->and($t['driver']['status'])->toBe('picked_up')->and($t['destination'])->toHaveKeys(['lat', 'lng']);

    $other = User::factory()->create();
    Sanctum::actingAs($other, ['customer']);
    $this->getJson("/api/v1/orders/{$orderId}/tracking")->assertNotFound();

    actingAsStaff($mgr);
    $fleet = $this->getJson('/api/v1/admin/dispatch/fleet')->assertOk()->json('data');
    expect($fleet)->toHaveCount(1)->and($fleet[0]['id'])->toBe($driver->id)->and($fleet[0]['open_jobs'])->toBe(1)->and($fleet[0]['lat'])->toBe(25.281);

    DriverLocation::query()->update(['recorded_at' => now()->subDays(10)]);
    $this->artisan('drivers:prune-locations')->assertSuccessful();
    expect(DriverLocation::count())->toBe(1); // an order's route is kept for a year
    DriverLocation::query()->update(['recorded_at' => now()->subDays(400)]);
    $this->artisan('drivers:prune-locations')->assertSuccessful();
    expect(DriverLocation::count())->toBe(0);
});

it('only lets people who watch the fleet or own the order listen to live positions', function () {
    $w = commerceWorld();
    $driver = driverFor($w->a);
    [$f, $mgr] = readyOrder($w);
    expect((new OrderChannel)->join($w->customer, $f->order_id))->toBeTrue()
        ->and((new OrderChannel)->join($driver, $f->order_id))->toBeFalse()
        ->and((new FleetChannel)->join($mgr))->toBeFalse()                       // branch staff use their branch channel, not the company-wide one
        ->and((new FleetChannel)->join(staffWithRole('super_admin')))->toBeTrue()
        ->and((new BranchChannel)->join($mgr, $w->a->id))->toBeTrue()
        ->and((new BranchChannel)->join($mgr, $w->b->id))->toBeFalse()
        ->and((new FleetChannel)->join($w->customer))->toBeFalse();
});

it('sends a driver position to the staff of the branches that driver works for only', function () {
    $w = commerceWorld();
    $driver = driverFor([$w->a]);
    Event::fake([DriverMoved::class]);
    Cache::flush();
    actingAsStaff($driver);
    $this->postJson('/api/v1/admin/dispatch/driver/location', ['latitude' => 25.28, 'longitude' => 51.52])->assertOk();
    Event::assertDispatched(DriverMoved::class, function (DriverMoved $e) use ($w) {
        $names = collect($e->broadcastOn())->map(fn ($c) => $c->name)->all();

        return in_array('private-branches.'.$w->a->id, $names, true) && ! in_array('private-branches.'.$w->b->id, $names, true) && in_array('private-fleet', $names, true);
    });
});
