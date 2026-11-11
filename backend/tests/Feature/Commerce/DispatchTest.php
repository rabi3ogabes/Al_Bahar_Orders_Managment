<?php

use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DriverProfile;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\UserRole;
use App\Domain\Orders\Models\Order;
use Illuminate\Support\Arr;

function driverFor($branches, bool $onDuty = true)
{
    $driver = staffWithRole('delivery_employee', null);
    foreach (Arr::wrap($branches) as $b) {
        BranchEmployee::create(['branch_id' => $b->id, 'user_id' => $driver->id, 'is_active' => true, 'is_delivery_driver' => true]);
        UserRole::create(['user_id' => $driver->id, 'role_id' => Role::where('key', 'delivery_employee')->value('id'), 'branch_id' => $b->id]);
    }
    DriverProfile::create(['user_id' => $driver->id, 'on_duty' => $onDuty]);

    return $driver;
}

function readyOrder($w): array
{
    test()->postJson('/api/v1/cart/items', ['variant_id' => $w->v1->id, 'quantity' => 1]);
    $orderId = quoteAndPlace(['address_id' => $w->address->id, 'payment_method' => 'cod'])->json('data.order.id');
    $f = Order::with('fulfillments')->find($orderId)->fulfillments->first();
    $mgr = actingAsStaff(staffWithRole('order_manager', $w->a));
    foreach (['accepted', 'preparing', 'ready'] as $s) {
        test()->postJson("/api/v1/admin/fulfillments/{$f->id}/transition", ['status' => $s])->assertOk();
    }

    return [$f->fresh(), $mgr];
}

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
