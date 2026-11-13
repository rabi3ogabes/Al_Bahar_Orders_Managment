<?php

// Fires real "driver.moved" frames through Reverb so the live map can be watched in a browser:
//   php tests/e2e-maps/emit-moves.php <driver-email> <steps> <ms-between> [lat,lng;lat,lng...]
// Without waypoints the driver drives from the West Bay branch to the QA customer address. Needs the app, Reverb and a seeded driver.
use App\Domain\Fulfillment\Events\DriverMoved;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DriverProfile;
use App\Domain\Identity\Models\User;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[, $email, $steps, $gap] = $argv + [null, 'driver1@albahar.qa', 20, 1000];
$route = isset($argv[4]) ? array_map(fn ($p) => array_map('floatval', explode(',', $p)), explode(';', $argv[4])) : [[25.3221, 51.5291], [25.3105, 51.5188]];
$driver = User::where('email', $email)->firstOrFail();
$profile = DriverProfile::findOrFail($driver->id);
$delivery = Delivery::with('fulfillment.order:id,number')->where('driver_id', $driver->id)->whereIn('status', ['assigned', 'accepted', 'picked_up', 'out_for_delivery'])->orderBy('created_at')->first();
$branchIds = $driver->hasMany(App\Domain\Branches\Models\BranchEmployee::class, 'user_id')->where('is_active', true)->pluck('branch_id')->all();

for ($i = 0; $i <= (int) $steps; $i++) {
    $f = (int) $steps === 0 ? 0 : $i / (int) $steps;
    $seg = min(count($route) - 2, (int) floor($f * (count($route) - 1)));
    $local = $f * (count($route) - 1) - $seg;
    $lat = $route[$seg][0] + ($route[$seg + 1][0] - $route[$seg][0]) * $local;
    $lng = $route[$seg][1] + ($route[$seg + 1][1] - $route[$seg][1]) * $local;
    $heading = (int) round(fmod(rad2deg(atan2($route[$seg + 1][1] - $route[$seg][1], $route[$seg + 1][0] - $route[$seg][0])) + 360, 360));
    $at = now();
    $profile->update(['last_lat' => $lat, 'last_lng' => $lng, 'last_heading' => $heading, 'last_speed_kmh' => 32, 'last_fix_at' => $at, 'last_seen_at' => $at]);
    event(new DriverMoved($driver->id, $driver->name, $lat, $lng, $delivery?->fulfillment?->order?->id, $delivery?->status->value, $heading, 32.0, $branchIds, 8, null, $profile->vehicle, $delivery?->fulfillment?->order?->number, $at->toIso8601String(), 0));
    echo "$i $lat $lng $heading\n";
    usleep((int) $gap * 1000);
}
