<?php

namespace App\Domain\Fulfillment\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Fulfillment\Enums\DeliveryStatus as DS;
use App\Domain\Fulfillment\Events\DriverMoved;
use App\Domain\Fulfillment\Events\OrderDriverMoved;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DriverLocation;
use App\Domain\Fulfillment\Models\DriverProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Support\BusinessTime;
use App\Support\DomainException;
use App\Support\Geo;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Hands deliveries to drivers. The admin chooses per branch whether the system does it (auto: the on-duty driver
 * with the fewest open jobs, longest idle first) or a branch employee does (manual). Drivers may work for several
 * branches; a branch may have several drivers and several employees.
 */
class DispatchService
{
    /** Statuses in which a delivery still occupies its driver. */
    private const OPEN = ['assigned', 'accepted', 'picked_up', 'out_for_delivery', 'rescheduled', 'failed'];

    /** Statuses in which the driver is on the road for the delivery, most advanced first (the breadcrumb goes to the first one). */
    private const MOVING = ['out_for_delivery' => 3, 'picked_up' => 2, 'accepted' => 1];

    /** Statuses in which the customer may follow the driver. */
    private const FOLLOWABLE = ['picked_up', 'out_for_delivery'];

    public function __construct(private readonly DeliveryService $deliveries, private readonly AuditLogger $audit) {}

    public function isDriver(User $user): bool
    {
        return BranchEmployee::where('user_id', $user->id)->where('is_active', true)->where('is_delivery_driver', true)->exists();
    }

    /** @return Collection<int, string> branch ids this user drives for */
    public function driverBranchIds(User $user): Collection
    {
        return BranchEmployee::where('user_id', $user->id)->where('is_active', true)->where('is_delivery_driver', true)->pluck('branch_id');
    }

    public function profile(User $driver): DriverProfile
    {
        return DriverProfile::firstOrCreate(['user_id' => $driver->id], ['on_duty' => false]);
    }

    public function setDuty(User $driver, bool $onDuty, ?float $lat = null, ?float $lng = null, ?string $vehicle = null): DriverProfile
    {
        if (! $this->isDriver($driver)) {
            throw DomainException::make('driver_not_eligible', 403);
        }
        $p = $this->profile($driver);
        $fix = $lat !== null && $lng !== null;
        $p->update(['on_duty' => $onDuty, 'last_lat' => $lat ?? $p->last_lat, 'last_lng' => $lng ?? $p->last_lng, 'last_seen_at' => now(),
            // A new position from the duty switch is a fresh fix; without one the old position keeps its own age.
            'last_fix_at' => $fix ? now() : $p->last_fix_at, 'last_heading' => $fix ? null : $p->last_heading, 'last_speed_kmh' => $fix ? null : $p->last_speed_kmh,
            'last_accuracy_m' => $fix ? null : $p->last_accuracy_m, 'vehicle' => $vehicle !== null ? ($vehicle === '' ? null : $vehicle) : $p->vehicle]);
        if ($onDuty) {
            $this->assignWaiting($driver);
        }

        return $p->fresh();
    }

    /**
     * The driver app reports its position (about every 10 s while on duty, also in the background).
     *
     * What is kept, in order: nothing while the driver is off duty with no job on the road (location is private outside
     * work); a fix older than the one already held is ignored; a very coarse fix (over platform.tracking.max_accuracy_m)
     * only proves the app is alive; otherwise the latest position is always updated. A breadcrumb is stored and broadcast
     * at most once every 3 s, and a fix within a few metres of the last breadcrumb is a duplicate (a driver standing
     * still is still recorded once every 45 s, so the map shows him alive).
     *
     * @param  CarbonInterface|null  $fixAt  when the phone took the fix; the server clock when absent
     * @return array{recorded: bool, reason: string|null} reason: off_duty | out_of_order | low_accuracy | rate_limited | duplicate
     */
    public function heartbeat(User $driver, float $lat, float $lng, ?float $speedKmh = null, ?int $heading = null, ?int $accuracy = null, ?CarbonInterface $fixAt = null, ?int $battery = null): array
    {
        $cfg = (array) config('platform.tracking');
        $p = $this->profile($driver);
        $now = now();
        if (! $p->on_duty && ! Delivery::where('driver_id', $driver->id)->whereIn('status', array_keys(self::MOVING))->exists()) {
            return ['recorded' => false, 'reason' => 'off_duty'];
        }
        $at = $fixAt === null || $fixAt->greaterThan($now) ? $now : $fixAt;
        if ($p->last_fix_at !== null && $at->lessThan($p->last_fix_at)) {
            return ['recorded' => false, 'reason' => 'out_of_order'];
        }
        if ($accuracy !== null && $accuracy > (int) $cfg['max_accuracy_m']) {
            $p->update(['last_seen_at' => $now]);

            return ['recorded' => false, 'reason' => 'low_accuracy'];
        }
        $p->update(['last_lat' => $lat, 'last_lng' => $lng, 'last_heading' => $heading, 'last_speed_kmh' => $speedKmh, 'last_accuracy_m' => $accuracy,
            'last_battery' => $battery ?? $p->last_battery, 'last_fix_at' => $at, 'last_seen_at' => $now]);
        if (! Cache::add('driver:beat:'.$driver->id, 1, 3)) {
            return ['recorded' => false, 'reason' => 'rate_limited'];
        }
        $last = Cache::get('driver:crumb:'.$driver->id);
        if (is_array($last) && Geo::distanceM($last[0], $last[1], $lat, $lng) < (float) $cfg['duplicate_radius_m']) {
            return ['recorded' => false, 'reason' => 'duplicate'];
        }
        Cache::put('driver:crumb:'.$driver->id, [$lat, $lng], (int) $cfg['duplicate_window_seconds']);

        $jobs = Delivery::with('fulfillment:id,order_id', 'fulfillment.order:id,number')->where('driver_id', $driver->id)->whereIn('status', array_keys(self::MOVING))->get()
            ->sortByDesc(fn (Delivery $d) => self::MOVING[$d->status->value].'|'.($d->assigned_at?->getTimestamp() ?? 0))->values();
        $delivery = $jobs->first();
        DriverLocation::create(['driver_id' => $driver->id, 'delivery_id' => $delivery?->id, 'lat' => $lat, 'lng' => $lng, 'speed_kmh' => $speedKmh,
            'heading' => $heading, 'accuracy_m' => $accuracy, 'recorded_at' => $at]);

        $iso = BusinessTime::iso($at);
        $age = DriverPosition::ageSeconds($at) ?? 0;
        $vehicle = $p->vehicle;
        rescue(function () use ($driver, $lat, $lng, $delivery, $heading, $speedKmh, $accuracy, $battery, $vehicle, $iso, $age) {
            event(new DriverMoved($driver->id, $driver->name, $lat, $lng, $delivery?->fulfillment?->order_id, $delivery?->status->value, $heading, $speedKmh,
                $this->driverBranchIds($driver)->all(), $accuracy, $battery, $vehicle, $delivery?->fulfillment?->order?->number, $iso, $age));
        }, report: true);
        // Each customer whose order this driver is carrying (a driver may have several on the road) follows the driver on their own channel.
        foreach ($jobs->filter(fn (Delivery $d) => in_array($d->status->value, self::FOLLOWABLE, true) && $d->fulfillment?->order_id) as $job) {
            rescue(fn () => event(new OrderDriverMoved($job->fulfillment->order_id, DriverPosition::firstName($driver->name), $lat, $lng, $job->status->value,
                $vehicle, $heading, $speedKmh, $accuracy, $iso, $age)), report: true);
        }

        return ['recorded' => true, 'reason' => null];
    }

    /**
     * Live fleet for the dashboard and the staff app: the drivers on duty, plus any driver still carrying a job after
     * switching off duty, each with the last position (see DriverPosition: age_seconds / stale tell a map to grey a marker
     * out) and the job in hand with its destination.
     *
     * @param  list<string>|null  $branchIds  null = all branches
     * @return Collection<int, array<string, mixed>>
     */
    public function fleet(?array $branchIds): Collection
    {
        $assignments = BranchEmployee::where('is_delivery_driver', true)->where('is_active', true)->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))->get();
        $ids = $assignments->pluck('user_id')->unique();
        $onRoad = Delivery::whereIn('driver_id', $ids)->whereIn('status', array_keys(self::MOVING))->pluck('driver_id')->unique();
        $profiles = DriverProfile::whereIn('user_id', $ids)->where(fn ($q) => $q->where('on_duty', true)->orWhereIn('user_id', $onRoad))->get()->keyBy('user_id');
        $jobs = Delivery::with('fulfillment.order:id,number,address_snapshot')->whereIn('driver_id', $profiles->keys())->whereIn('status', self::OPEN)->get()->groupBy('driver_id');
        $branches = Branch::whereIn('id', $assignments->pluck('branch_id'))->get()->keyBy('id');

        return User::whereIn('id', $profiles->keys())->orderBy('name')->get()->map(function (User $u) use ($profiles, $jobs, $assignments, $branches) {
            $p = $profiles[$u->id];
            $driverJobs = $jobs->get($u->id) ?? collect();
            // The job to show is the one on the road (most advanced first), else the first open one.
            $job = $driverJobs->sortByDesc(fn (Delivery $d) => self::MOVING[$d->status->value] ?? 0)->first();
            $dest = $job?->fulfillment?->order?->address_snapshot ?? [];
            $pos = DriverPosition::of($p);

            return [
                'id' => $u->id, 'name' => $u->name, 'phone' => $u->phone, 'vehicle' => $p->vehicle, 'on_duty' => (bool) $p->on_duty,
                'lat' => $pos['lat'] ?? null, 'lng' => $pos['lng'] ?? null, 'heading' => $pos['heading'] ?? null, 'speed_kmh' => $pos['speed_kmh'] ?? null,
                'accuracy_m' => $pos['accuracy_m'] ?? null, 'battery' => $p->last_battery, 'at' => $pos['at'] ?? null,
                'age_seconds' => $pos['age_seconds'] ?? null, 'stale' => $pos['stale'] ?? true,
                'last_seen_at' => $p->last_seen_at?->toIso8601String(),
                'branches' => $assignments->where('user_id', $u->id)->map(fn ($a) => $branches[$a->branch_id]?->localized('name'))->filter()->values(),
                'open_jobs' => $driverJobs->count(),
                'job' => $job ? ['delivery_id' => $job->id, 'status' => $job->status->value, 'order_id' => $job->fulfillment?->order?->id, 'order_number' => $job->fulfillment?->order?->number,
                    'destination' => Geo::validPoint($dest['latitude'] ?? null, $dest['longitude'] ?? null) ? ['lat' => (float) $dest['latitude'], 'lng' => (float) $dest['longitude']] : null] : null,
            ];
        })->values();
    }

    /**
     * Drivers of a branch with their open workload, on-duty first then least busy.
     *
     * @return Collection<int, array{id: string, name: string, phone: ?string, on_duty: bool, open_jobs: int, last_seen_at: ?string}>
     */
    public function branchDrivers(Branch $branch): Collection
    {
        $ids = BranchEmployee::where('branch_id', $branch->id)->where('is_active', true)->where('is_delivery_driver', true)->pluck('user_id');
        $load = Delivery::whereIn('driver_id', $ids)->whereIn('status', self::OPEN)->groupBy('driver_id')->selectRaw('driver_id, count(*) as n')->pluck('n', 'driver_id');
        $profiles = DriverProfile::whereIn('user_id', $ids)->get()->keyBy('user_id');

        return User::whereIn('id', $ids)->where('status', 'active')->get()->map(fn (User $u) => [
            'id' => $u->id, 'name' => $u->name, 'phone' => $u->phone,
            'on_duty' => (bool) $profiles->get($u->id)?->on_duty, 'open_jobs' => (int) $load->get($u->id, 0),
            'last_seen_at' => $profiles->get($u->id)?->last_seen_at?->toIso8601String(),
        ])->sortBy([['on_duty', 'desc'], ['open_jobs', 'asc'], ['name', 'asc']])->values();
    }

    /** Picks the best on-duty driver of the branch, or null when none is available. */
    public function pickDriver(Branch $branch): ?User
    {
        $onDuty = $this->branchDrivers($branch)->where('on_duty', true);
        if ($onDuty->isEmpty()) {
            return null;
        }
        $minLoad = $onDuty->min('open_jobs');
        $tied = $onDuty->where('open_jobs', $minLoad)->pluck('id');
        $winner = DriverProfile::whereIn('user_id', $tied)->orderByRaw('last_assigned_at ASC NULLS FIRST')->first();

        return User::find($winner?->user_id ?? $tied->first());
    }

    /** Called when a delivery fulfillment is ready: in auto mode creates the delivery and assigns a driver now. */
    public function onFulfillmentReady(OrderFulfillment $f): ?Delivery
    {
        $branch = Branch::find($f->branch_id);
        if (! $branch || $branch->dispatch_mode !== 'auto' || $f->type !== 'delivery') {
            return null;
        }
        $delivery = $f->activeDelivery() ?? $this->deliveries->createSystem($f);

        return $this->autoAssign($delivery);
    }

    /** Auto mode: gives a pending internal delivery to the best driver. Stays pending (staff see it) when nobody is on duty. */
    public function autoAssign(Delivery $delivery): ?Delivery
    {
        if ($delivery->mode !== 'internal' || $delivery->status !== DS::Pending) {
            return null;
        }
        $branch = Branch::findOrFail($delivery->branch_id);
        $driver = $this->pickDriver($branch);
        if (! $driver) {
            return null;
        }

        return DB::transaction(function () use ($delivery, $driver) {
            $fresh = Delivery::whereKey($delivery->id)->lockForUpdate()->first();
            if ($fresh->status !== DS::Pending) {
                return null;
            }
            $d = $this->deliveries->assignSystem($fresh, $driver);
            $this->profile($driver)->update(['last_assigned_at' => now()]);
            $this->audit->log('delivery.auto_assigned', $d, [], ['driver_id' => $driver->id], branchId: $d->branch_id);

            return $d;
        });
    }

    /** A driver just went on duty: hand them jobs that were waiting in auto-mode branches. */
    public function assignWaiting(User $driver): void
    {
        $branches = Branch::whereIn('id', $this->driverBranchIds($driver))->where('dispatch_mode', 'auto')->pluck('id');
        Delivery::whereIn('branch_id', $branches)->where('status', DS::Pending->value)->where('mode', 'internal')->orderBy('created_at')->limit(20)->get()
            ->each(fn (Delivery $d) => $this->autoAssign($d));
    }

    /** Manual mode: a branch employee picks the driver (create the delivery first if it does not exist yet). */
    public function staffAssign(OrderFulfillment $f, User $driver, User $staff): Delivery
    {
        $delivery = $f->activeDelivery() ?? $this->deliveries->create($f, $staff);
        if ($delivery->status === DS::Pending || $delivery->status === DS::Assigned) {
            $d = $this->deliveries->assignDriver($delivery, $driver, $staff);
            $this->profile($driver)->update(['last_assigned_at' => now()]);

            return $d;
        }
        throw DomainException::make('invalid_state_transition', 409, ['from' => $delivery->status->value]);
    }
}
