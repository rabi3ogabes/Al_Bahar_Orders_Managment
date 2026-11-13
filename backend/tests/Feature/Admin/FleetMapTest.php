<?php

use App\Domain\Fulfillment\Models\DriverProfile;
use App\Domain\Identity\Models\User;
use App\Livewire\Admin\Sales\FleetMap;
use Livewire\Livewire;

it('shows the live fleet to staff who can view deliveries, scoped to their branches', function () {
    $w = commerceWorld();
    $mine = driverFor($w->a);
    $other = driverFor($w->b);
    $mine->driverProfile?->update(['last_lat' => 25.3, 'last_lng' => 51.5]);
    DriverProfile::where('user_id', $mine->id)->update(['last_lat' => 25.3, 'last_lng' => 51.5, 'last_seen_at' => now()]);
    $mgr = actingAsStaff(staffWithRole('order_manager', $w->a));
    Livewire::actingAs($mgr)->test(FleetMap::class)->assertOk()->assertSee($mine->name)->assertDontSee($other->name)->assertDispatched('fleet-updated');
});

it('refuses the fleet page to people without delivery access', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.fleet'))->assertRedirect();
});

it('hands the map its rows, the branch pins, the channels and the server clock in one event, with the order id for the "Open order" link', function () {
    $w = commerceWorld();
    $w->a->update(['latitude' => 25.3221, 'longitude' => 51.5291]);
    $driver = driverFor($w->a);
    DriverProfile::where('user_id', $driver->id)->update(['last_lat' => 25.3, 'last_lng' => 51.5, 'last_seen_at' => now()]);
    [$f, $mgr] = readyOrder($w);
    $this->postJson("/api/v1/admin/dispatch/fulfillments/{$f->id}/assign", ['driver_id' => $driver->id])->assertOk();

    Livewire::actingAs($mgr)->test(FleetMap::class)->assertOk()
        ->assertDispatched('fleet-updated', function (string $event, array $params) use ($driver, $w, $f) {
            $row = collect($params['drivers'])->firstWhere('id', $driver->id);

            return $row['job']['order_id'] === $f->order_id
                && $params['channels'] === ['branches.'.$w->a->id]
                && $params['branches'][0]['name'] === $w->a->name_en
                && $params['branches'][0]['lat'] === 25.3221
                && is_int($params['server_time']) && abs($params['server_time'] - now()->getTimestampMs()) < 60_000;
        })
        ->assertSeeHtml('data-boot=');
});

it('mounts the map once: the page start-up data sits on the wire:ignore element, never in a per-render x-data value', function () {
    $w = commerceWorld();
    $mgr = actingAsStaff(staffWithRole('order_manager', $w->a));
    $html = Livewire::actingAs($mgr)->test(FleetMap::class)->html();
    expect($html)->toContain('x-data="fleetMap"')->and($html)->toContain('wire:ignore')->and($html)->not->toContain('wire:poll');
});
