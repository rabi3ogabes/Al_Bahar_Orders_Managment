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
