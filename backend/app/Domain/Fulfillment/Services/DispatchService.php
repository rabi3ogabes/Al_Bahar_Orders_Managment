<?php

namespace App\Domain\Fulfillment\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Fulfillment\Enums\DeliveryStatus as DS;
use App\Domain\Fulfillment\Events\DriverMoved;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DriverLocation;
use App\Domain\Fulfillment\Models\DriverProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Support\DomainException;
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

    public function setDuty(User $driver, bool $onDuty, ?float $lat = null, ?float $lng = null): DriverProfile
    {
        if (! $this->isDriver($driver)) {
            throw DomainException::make('driver_not_eligible', 403);
        }
        $p = $this->profile($driver);
        $p->update(['on_duty' => $onDuty, 'last_lat' => $lat ?? $p->last_lat, 'last_lng' => $lng ?? $p->last_lng, 'last_seen_at' => now()]);
        if ($onDuty) {
            $this->assignWaiting($driver);
        }

        return $p->fresh();
    }

    /**
     * The driver app reports its position (about every 10 s while on duty, also in the background). Always keeps the latest
     * position; stores a breadcrumb and broadcasts at most once every 3 s so a chatty phone cannot flood the sockets.
     */
    public function heartbeat(User $driver, float $lat, float $lng, ?float $speedKmh = null, ?int $heading = null, ?int $accuracy = null): void
    {
        $this->profile($driver)->update(['last_lat' => $lat, 'last_lng' => $lng, 'last_seen_at' => now()]);
        if (! Cache::add('driver:beat:'.$driver->id, 1, 3)) {
            return;
        }
        $delivery = Delivery::where('driver_id', $driver->id)->whereIn('status', ['accepted', 'picked_up', 'out_for_delivery'])->latest('assigned_at')->first();
        DriverLocation::create(['driver_id' => $driver->id, 'delivery_id' => $delivery?->id, 'lat' => $lat, 'lng' => $lng, 'speed_kmh' => $speedKmh,
            'heading' => $heading, 'accuracy_m' => $accuracy, 'recorded_at' => now()]);
        $orderId = $delivery ? $delivery->fulfillment()->value('order_id') : null;
        rescue(fn () => event(new DriverMoved($driver->id, $driver->name, $lat, $lng, $orderId, $delivery?->status->value, $heading, $speedKmh)), report: true);
    }

    /**
     * Live fleet for the dashboard: drivers on duty (or heard from in the last 15 minutes) with their last position and job.
     *
     * @param  list<string>|null  $branchIds  null = all branches
     * @return Collection<int, array<string, mixed>>
     */
    public function fleet(?array $branchIds): Collection
    {
        $assignments = BranchEmployee::where('is_delivery_driver', true)->where('is_active', true)->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))->get();
        $ids = $assignments->pluck('user_id')->unique();
        $profiles = DriverProfile::whereIn('user_id', $ids)->where(fn ($q) => $q->where('on_duty', true)->orWhere('last_seen_at', '>=', now()->subMinutes(15)))->get()->keyBy('user_id');
        $jobs = Delivery::with('fulfillment.order:id,number')->whereIn('driver_id', $profiles->keys())->whereIn('status', self::OPEN)->get()->groupBy('driver_id');
        $branches = Branch::whereIn('id', $assignments->pluck('branch_id'))->get()->keyBy('id');

        return User::whereIn('id', $profiles->keys())->get()->map(function (User $u) use ($profiles, $jobs, $assignments, $branches) {
            $p = $profiles[$u->id];
            $job = $jobs->get($u->id)?->first();

            return [
                'id' => $u->id, 'name' => $u->name, 'phone' => $u->phone, 'on_duty' => (bool) $p->on_duty,
                'lat' => $p->last_lat !== null ? (float) $p->last_lat : null, 'lng' => $p->last_lng !== null ? (float) $p->last_lng : null,
                'last_seen_at' => $p->last_seen_at?->toIso8601String(),
                'branches' => $assignments->where('user_id', $u->id)->map(fn ($a) => $branches[$a->branch_id]?->localized('name'))->filter()->values(),
                'open_jobs' => (int) ($jobs->get($u->id)?->count() ?? 0),
                'job' => $job ? ['delivery_id' => $job->id, 'status' => $job->status->value, 'order_number' => $job->fulfillment?->order?->number] : null,
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
