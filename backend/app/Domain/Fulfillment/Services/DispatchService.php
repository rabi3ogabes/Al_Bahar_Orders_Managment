<?php

namespace App\Domain\Fulfillment\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Fulfillment\Enums\DeliveryStatus as DS;
use App\Domain\Fulfillment\Events\DriverMoved;
use App\Domain\Fulfillment\Events\OrderDriverMoved;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DeliveryDecline;
use App\Domain\Fulfillment\Models\DriverLocation;
use App\Domain\Fulfillment\Models\DriverProfile;
use App\Domain\Fulfillment\Services\Eta\EtaService;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Support\BusinessTime;
use App\Support\DomainException;
use App\Support\Geo;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

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

    /** Jobs the driver has taken on and is working: he cannot go off duty (or be set off duty) over them. */
    public const IN_PROGRESS = ['accepted', 'picked_up', 'out_for_delivery', 'failed'];

    /** Straight-line distance beyond which "is he close?" is not worth asking a routing server (about 10 minutes of motorway). */
    private const NEARBY_CHECK_WITHIN_M = 15000;

    public function __construct(
        private readonly DeliveryService $deliveries,
        private readonly AuditLogger $audit,
        private readonly DispatchSettings $config,
        private readonly DriverDispatchAlerts $alerts,
    ) {}

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

    /**
     * Duty on or off. Going on duty hands over the jobs that were waiting. Going OFF duty while he still has jobs he accepted or has on the road is
     * refused (409 `jobs_in_progress`, with how many): he finishes them, or the branch takes them back. Under the profile's row lock, so a second
     * tap, a second phone and the watchdog cannot both decide; switching off when already off is harmless.
     */
    public function setDuty(User $driver, bool $onDuty, ?float $lat = null, ?float $lng = null, ?string $vehicle = null): DriverProfile
    {
        if (! $this->isDriver($driver)) {
            throw DomainException::make('driver_not_eligible', 403);
        }
        $this->profile($driver);
        $p = DB::transaction(function () use ($driver, $onDuty, $lat, $lng, $vehicle) {
            $p = DriverProfile::query()->whereKey($driver->id)->lockForUpdate()->firstOrFail();
            if (! $onDuty && $p->on_duty && ($open = $this->inProgressCount($driver)) > 0) {
                throw DriverError::make('jobs_in_progress', 409, ['count' => $open]);
            }
            $fix = $lat !== null && $lng !== null;
            $p->update(['on_duty' => $onDuty, 'last_lat' => $lat ?? $p->last_lat, 'last_lng' => $lng ?? $p->last_lng, 'last_seen_at' => now(),
                // The day starts when he goes on duty, and a duty switch he repeats does not move it.
                'on_duty_since' => $onDuty ? ($p->on_duty ? ($p->on_duty_since ?? now()) : now()) : null,
                // A new position from the duty switch is a fresh fix; without one the old position keeps its own age.
                'last_fix_at' => $fix ? now() : $p->last_fix_at, 'last_heading' => $fix ? null : $p->last_heading, 'last_speed_kmh' => $fix ? null : $p->last_speed_kmh,
                'last_accuracy_m' => $fix ? null : $p->last_accuracy_m, 'vehicle' => $vehicle !== null ? ($vehicle === '' ? null : $vehicle) : $p->vehicle]);

            return $p;
        });
        if ($onDuty) {
            $this->assignWaiting($driver);
        } else {
            // Off duty means off: a job he was given and never accepted goes to someone who is working (his accepted jobs are not touched: he cannot go off with those).
            $this->releaseWaitingJobsOf($driver->id);
        }

        return $p->fresh();
    }

    /** Takes back every job given to this driver that he has not accepted, and finds each a new driver where the branch gives jobs out by itself. */
    private function releaseWaitingJobsOf(string $driverId): void
    {
        $out = ['released' => 0, 'reassigned' => 0, 'gave_up' => 0, 'off_duty' => 0, 'quiet_with_jobs' => 0];
        foreach (Delivery::query()->where('driver_id', $driverId)->where('status', DS::Assigned->value)->get() as $job) {
            $this->guarded(function () use ($job, &$out) {
                $this->takeBack($job, DeliveryDecline::OFF_DUTY, null, $out);
            });
        }
    }

    /** How many jobs the driver has accepted or has on the road (what stops him going off duty). */
    public function inProgressCount(User $driver): int
    {
        return $this->inProgressCountOf($driver->id);
    }

    private function inProgressCountOf(string $driverId): int
    {
        return Delivery::query()->where('driver_id', $driverId)->whereIn('status', self::IN_PROGRESS)->count();
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

        $jobs = Delivery::with('fulfillment:id,order_id', 'fulfillment.order:id,number,address_snapshot')->where('driver_id', $driver->id)->whereIn('status', array_keys(self::MOVING))->get()
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
        // The customer is told once that the driver is close (a failure here never reaches the driver's phone).
        foreach ($jobs->filter(fn (Delivery $d) => $d->status === DS::OutForDelivery && $d->nearby_at === null && $d->arrived_at === null) as $job) {
            rescue(fn () => $this->announceIfNearby($job, $lat, $lng), report: true);
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

        /** @var Collection<int, array<string, mixed>> $fleet */
        $fleet = User::whereIn('id', $profiles->keys())->orderBy('name')->get()->map(function (User $u) use ($profiles, $jobs, $assignments, $branches) {
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

        return $fleet;
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

    /**
     * Picks the best on-duty driver of the branch, or null when none is available.
     *
     * @param  list<string>  $excluding  drivers who must not get this job (they handed it back, or did not answer)
     */
    public function pickDriver(Branch $branch, array $excluding = []): ?User
    {
        $onDuty = $this->branchDrivers($branch)->where('on_duty', true)->reject(fn (array $d) => in_array($d['id'], $excluding, true));
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

    /**
     * Auto mode: gives a pending internal delivery to the best driver, never to one who handed this delivery back or did not answer for it.
     * Stays pending (staff see it) when nobody is on duty.
     */
    public function autoAssign(Delivery $delivery): ?Delivery
    {
        if ($delivery->mode !== 'internal' || $delivery->status !== DS::Pending) {
            return null;
        }
        $branch = Branch::findOrFail($delivery->branch_id);
        $driver = $this->pickDriver($branch, DeliveryDecline::query()->where('delivery_id', $delivery->id)->pluck('driver_id')->all());
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

    /**
     * The driver hands a job he was given (and has not accepted) back. The job returns to the waiting pool; in a branch that gives jobs out by
     * itself it goes to the next driver at once (never the one who declined); the branch managers are told either way. A repeated call changes
     * nothing and tells nobody twice.
     */
    public function declineJob(Delivery $delivery, User $driver, string $reason, ?string $note = null): Delivery
    {
        [$d, $changed] = $this->deliveries->decline($delivery, $driver, $reason, $note);
        if (! $changed) {
            return $d;
        }
        $auto = Branch::query()->whereKey($d->branch_id)->value('dispatch_mode') === 'auto';
        $next = null;
        if ($auto) {
            $given = null;
            rescue(function () use ($d, &$given) {
                $given = $this->autoAssign($d->fresh() ?? $d);
            }, report: true);
            $next = $given?->driver_id ? User::query()->find($given->driver_id) : null;
        }
        rescue(fn () => $this->alerts->declined($d->loadMissing('fulfillment.order'), $driver, $reason, $next, $auto), report: true);

        return $d->fresh() ?? $d;
    }

    /**
     * What a minute of watching finds (the `dispatch:watchdog` command runs it every minute; every step is safe to repeat and to run twice at once):
     *
     *  - a delivery given to a driver who did not accept it within dispatch.accept_timeout_min is taken back and, in a branch that gives jobs out
     *    by itself, offered to the next driver (never one who already failed to answer for it). After dispatch.max_auto_attempts silent drivers the
     *    system stops: it stays waiting, the branch managers are told, and the Needs attention list shows it ("Ready, no driver yet").
     *  - a driver on duty who has not been heard from for dispatch.stale_duty_min minutes is set off duty and the jobs he had not accepted are taken
     *    back like the above. Jobs he accepted or has on the road are NEVER touched: they stay his, and the managers are told he went quiet carrying them.
     *
     * @return array{released: int, reassigned: int, gave_up: int, off_duty: int, quiet_with_jobs: int}
     */
    public function watchdog(?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());
        $out = ['released' => 0, 'reassigned' => 0, 'gave_up' => 0, 'off_duty' => 0, 'quiet_with_jobs' => 0];

        $timeout = $this->config->acceptTimeoutMin();
        if ($timeout > 0) {
            $cut = $now->subMinutes($timeout);
            $silent = Delivery::query()->where('status', DS::Assigned->value)->where('mode', 'internal')->whereNotNull('driver_id')->where('assigned_at', '<=', $cut)
                ->orderBy('assigned_at')->limit(200)->get();
            foreach ($silent as $job) {
                $this->guarded(function () use ($job, $cut, &$out) {
                    $this->takeBack($job, DeliveryDecline::TIMEOUT, $cut, $out);
                });
            }
        }

        $minutes = $this->config->staleDutyMin();
        $stale = DriverProfile::query()->where('on_duty', true)->whereRaw('coalesce(last_seen_at, updated_at) <= ?', [$now->subMinutes($minutes)])->orderBy('last_seen_at')->limit(200)->get();
        foreach ($stale as $profile) {
            $this->guarded(function () use ($profile, $now, $minutes, &$out) {
                $this->setSilentDriverOffDuty($profile->user_id, $now, $minutes, $out);
            });
        }

        return $out;
    }

    /** One bad row must not stop the watchdog for the others (or for the next minute). */
    private function guarded(callable $step): void
    {
        try {
            $step();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Takes one unaccepted job back from its driver and finds it a new one when it may. A driver who did not answer (`timeout`) is replaced in
     * silence unless the system has used up its turns (or a person chooses the drivers at this branch): then the managers are told. A driver who went
     * off duty (`off_duty`) hands the job over like a decline: the managers are told who got it, or that it waits.
     *
     * @param  array{released: int, reassigned: int, gave_up: int, off_duty: int, quiet_with_jobs: int}  $out
     */
    private function takeBack(Delivery $job, string $reason, ?CarbonInterface $assignedBefore, array &$out): void
    {
        $previous = $this->deliveries->releaseUnaccepted($job, $reason, $assignedBefore);
        if ($previous === null) {
            return;
        }
        $out['released']++;
        $fresh = ($job->fresh() ?? $job)->loadMissing('fulfillment.order');
        $attempts = DeliveryDecline::query()->where('delivery_id', $job->id)->where('reason', DeliveryDecline::TIMEOUT)->count();
        $auto = Branch::query()->whereKey($job->branch_id)->value('dispatch_mode') === 'auto';
        $spent = $reason === DeliveryDecline::TIMEOUT && $attempts >= $this->config->maxAutoAttempts();
        $next = null;
        if ($auto && ! $spent) {
            $given = $this->autoAssign($fresh);
            $next = $given?->driver_id ? User::query()->find($given->driver_id) : null;
            $out['reassigned'] += $given !== null ? 1 : 0;
        }
        $out['gave_up'] += $spent ? 1 : 0;
        if ($reason === DeliveryDecline::TIMEOUT) {
            if ($spent || ! $auto) {
                $this->guarded(fn () => $this->alerts->nobodyTook($fresh, max(1, $attempts)));
            }

            return;
        }
        $gone = User::query()->find($previous);
        if ($gone !== null) {
            $this->guarded(fn () => $this->alerts->declined($fresh, $gone, $reason, $next, $auto));
        }
    }

    /**
     * Sets one silent driver off duty (under his profile's lock, after looking again) and takes back the jobs he had not accepted.
     *
     * @param  array{released: int, reassigned: int, gave_up: int, off_duty: int, quiet_with_jobs: int}  $out
     */
    private function setSilentDriverOffDuty(string $driverId, CarbonImmutable $now, int $minutes, array &$out): void
    {
        $cut = $now->subMinutes($minutes);
        $switched = DB::transaction(function () use ($driverId, $cut) {
            $p = DriverProfile::query()->whereKey($driverId)->lockForUpdate()->first();
            if ($p === null || ! $p->on_duty || ($p->last_seen_at ?? $p->updated_at)?->greaterThan($cut)) {
                return false; // he came back, or was switched off, since the scan
            }
            $p->update(['on_duty' => false, 'on_duty_since' => null]);
            $this->audit->log('driver.auto_off_duty', 'DriverProfile', ['on_duty' => true], ['on_duty' => false], ['driver_id' => $driverId]);

            return true;
        });
        if (! $switched) {
            return;
        }
        $out['off_duty']++;
        $driver = User::query()->find($driverId);
        $jobs = Delivery::query()->where('driver_id', $driverId)->where('status', DS::Assigned->value)->get();
        foreach ($jobs as $job) {
            $this->guarded(function () use ($job, &$out) {
                $this->takeBack($job, DeliveryDecline::OFF_DUTY, null, $out);
            });
        }
        $carrying = $this->inProgressCountOf($driverId);
        if ($driver !== null && ($carrying > 0 || $jobs->isNotEmpty())) {
            $out['quiet_with_jobs'] += $carrying > 0 ? 1 : 0;
            $this->guarded(fn () => $this->alerts->wentQuiet($this->driverBranchIds($driver)->all(), $driver, $minutes, $carrying));
        }
    }

    /** The driver is out for delivery and the position he just reported is close to the customer: tell the customer once. */
    private function announceIfNearby(Delivery $job, float $lat, float $lng): void
    {
        // One look per delivery every 15 seconds: the phone reports far more often than the customer's "he is close" needs, and the arrival
        // time is cached per delivery anyway (EtaService), so a routing server is never asked on every position.
        if (! Cache::add('delivery:nearby:look:'.$job->id, 1, 15)) {
            return;
        }
        $dest = (array) ($job->fulfillment?->order?->address_snapshot ?? []);
        $toLat = $dest['latitude'] ?? null;
        $toLng = $dest['longitude'] ?? null;
        if (! Geo::validPoint($toLat, $toLng)) {
            return; // no pin on the map for this address: there is nothing to measure against
        }
        $metres = Geo::distanceM($lat, $lng, (float) $toLat, (float) $toLng);
        if ($metres > self::NEARBY_CHECK_WITHIN_M) {
            return;
        }
        try {
            $close = app(EtaService::class)->between($job->id, $lat, $lng, (float) $toLat, (float) $toLng)['minutes'] <= $this->config->nearbyEtaMin();
        } catch (Throwable) {
            $close = $metres <= $this->config->nearbyDistanceM(); // no arrival time could be worked out: the straight line decides
        }
        if (! $close) {
            return;
        }
        // Claimed in the database first, so two position reports at the same moment send one message.
        $claimed = Delivery::query()->whereKey($job->id)->where('status', DS::OutForDelivery->value)->whereNull('nearby_at')->whereNull('arrived_at')->update(['nearby_at' => now()]);
        if ($claimed === 1) {
            app(DeliveryCustomerAlerts::class)->nearby($job->id);
        }
    }

    /** Manual mode: a branch employee picks the driver (create the delivery first if it does not exist yet). */
    public function staffAssign(OrderFulfillment $f, User $driver, User $staff): Delivery
    {
        $delivery = $f->activeDelivery() ?? $this->deliveries->create($f, $staff);
        // assignDriver decides what can still be (re)assigned: waiting, assigned, accepted by the previous driver (never once the goods are on the road).
        $d = $this->deliveries->assignDriver($delivery, $driver, $staff);
        $this->profile($driver)->update(['last_assigned_at' => now()]);

        return $d;
    }
}
