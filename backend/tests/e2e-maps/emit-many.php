<?php

// Random-walks many drivers at once through real "driver.moved" frames (scale check of the fleet map):
//   php tests/e2e-maps/emit-many.php <seconds> <moves-per-second>
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Fulfillment\Events\DriverMoved;
use App\Domain\Fulfillment\Models\DriverProfile;
use App\Domain\Identity\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $seconds, $rate] = $argv + [null, 10, 40];
$drivers = DriverProfile::where('on_duty', true)->whereNotNull('last_lat')->get()->all();
$names = User::whereIn('id', array_map(fn ($p) => $p->user_id, $drivers))->pluck('name', 'id');
$branches = BranchEmployee::whereIn('user_id', array_keys($names->all()))->get()->groupBy('user_id')->map(fn ($g) => $g->pluck('branch_id')->all());
$pos = [];
foreach ($drivers as $p) {
    $pos[$p->user_id] = [(float) $p->last_lat, (float) $p->last_lng, random_int(0, 359)];
}
for ($t = 0; $t < (int) $seconds; $t++) {
    $start = microtime(true);
    for ($i = 0; $i < (int) $rate; $i++) {
        $p = $drivers[array_rand($drivers)];
        [$lat, $lng, $h] = $pos[$p->user_id];
        $h = ($h + random_int(-25, 25) + 360) % 360;
        $lat += cos(deg2rad($h)) * 0.0004;
        $lng += sin(deg2rad($h)) * 0.0004;
        $pos[$p->user_id] = [$lat, $lng, $h];
        event(new DriverMoved($p->user_id, $names[$p->user_id], $lat, $lng, null, null, $h, 30.0, $branches[$p->user_id] ?? [], 8, null, null, null, now()->toIso8601String(), 0));
    }
    $left = 1_000_000 - (int) ((microtime(true) - $start) * 1_000_000);
    if ($left > 0) {
        usleep($left);
    }
}
echo "done\n";
