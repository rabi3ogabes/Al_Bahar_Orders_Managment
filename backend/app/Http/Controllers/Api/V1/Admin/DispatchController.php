<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Branches\Models\Branch;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Services\DispatchService;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Http\Controllers\Api\V1\ApiController;
use App\Domain\Fulfillment\Services\DriverPosition;
use App\Support\BusinessTime;
use App\Support\DomainException;
use App\Support\Geo;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile dispatch surface for the two staff apps (prefix /api/v1/admin/dispatch):
 *  - Driver app: duty switch, live position, my jobs. Job steps (accept / pickup / out-for-delivery / deliver / fail)
 *    use POST /admin/deliveries/{id}/{action}.
 *  - Branch staff app: workload overview, the delivery queue, the branch's drivers, and assigning a driver
 *    (when the branch is on manual dispatch). Order steps use POST /admin/fulfillments/{id}/transition.
 *
 * @tags Dispatch
 */
class DispatchController extends ApiController
{
    public function __construct(private readonly DispatchService $dispatch) {}

    // ── Driver app ─────────────────────────────────────────────────────────

    public function driver(): JsonResponse
    {
        $u = $this->user();
        $this->assertDriver($u);
        $p = $this->dispatch->profile($u);
        $branches = Branch::whereIn('id', $this->dispatch->driverBranchIds($u))->get();

        return response()->json(['data' => [
            'id' => $u->id, 'name' => $u->name, 'phone' => $u->phone, 'on_duty' => (bool) $p->on_duty, 'vehicle' => $p->vehicle,
            'branches' => $branches->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->localized('name'), 'dispatch_mode' => $b->dispatch_mode])->values(),
            'open_jobs' => Delivery::where('driver_id', $u->id)->whereIn('status', ['assigned', 'accepted', 'picked_up', 'out_for_delivery', 'rescheduled', 'failed'])->count(),
        ]]);
    }

    public function duty(Request $request): JsonResponse
    {
        $d = $request->validate(['on_duty' => 'required|boolean', 'latitude' => 'nullable|numeric|between:-90,90|required_with:longitude', 'longitude' => 'nullable|numeric|between:-180,180|required_with:latitude',
            'vehicle' => 'nullable|string|max:60']);
        $lat = isset($d['latitude']) ? (float) $d['latitude'] : null;
        $lng = isset($d['longitude']) ? (float) $d['longitude'] : null;
        $this->assertInServiceArea($lat, $lng);
        $p = $this->dispatch->setDuty($this->user(), (bool) $d['on_duty'], $lat, $lng, array_key_exists('vehicle', $d) ? trim((string) $d['vehicle']) : null);

        return response()->json(['data' => ['on_duty' => $p->on_duty, 'vehicle' => $p->vehicle]]);
    }

    /**
     * The driver app's GPS ping. 422 when the fix itself is unusable (outside Qatar, in the future, older than
     * platform.tracking.max_fix_age_minutes); 200 with recorded=false and a reason when it is fine but not stored
     * (off_duty, rate_limited, duplicate, low_accuracy, out_of_order), so the app never retries those.
     */
    public function location(Request $request): JsonResponse
    {
        $d = $request->validate(['latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180',
            'speed_kmh' => 'nullable|numeric|between:0,300', 'heading' => 'nullable|integer|between:0,360', 'accuracy' => 'nullable|integer|between:0,32767',
            'battery' => 'nullable|integer|between:0,100', 'recorded_at' => 'nullable|date']);
        $this->assertDriver($this->user());
        $lat = (float) $d['latitude'];
        $lng = (float) $d['longitude'];
        $this->assertInServiceArea($lat, $lng);
        $result = $this->dispatch->heartbeat($this->user(), $lat, $lng, isset($d['speed_kmh']) ? (float) $d['speed_kmh'] : null, $d['heading'] ?? null, $d['accuracy'] ?? null,
            isset($d['recorded_at']) ? $this->fixTime($d['recorded_at']) : null, $d['battery'] ?? null);

        return response()->json(['data' => ['ok' => true] + $result]);
    }

    /** My jobs: scope=active (default) or history (last 30 finished). */
    public function jobs(Request $request): JsonResponse
    {
        $u = $this->user();
        $this->assertDriver($u);
        $history = $request->query('scope') === 'history';
        $rows = Delivery::with(['fulfillment.order', 'fulfillment.branch'])->withCount([])
            ->where('driver_id', $u->id)
            ->when($history, fn ($q) => $q->whereIn('status', ['delivered', 'returned', 'cancelled'])->orderByDesc('updated_at')->limit(30),
                fn ($q) => $q->whereNotIn('status', ['delivered', 'returned', 'cancelled'])->orderBy('scheduled_for')->orderBy('assigned_at'))
            ->get();

        return response()->json(['data' => $rows->map(fn (Delivery $d) => $this->job($d))->values()]);
    }

    /**
     * Live fleet for the dashboard map and the staff app: the drivers on duty (branch-scoped) with last position, how old it is
     * (age_seconds / stale) and the job in hand.
     */
    public function fleet(Request $request): JsonResponse
    {
        $scope = $this->scope('deliveries.view', $request->validate(['branch_id' => 'nullable|uuid'])['branch_id'] ?? null);

        return response()->json(['data' => $this->dispatch->fleet($scope->all ? null : $scope->branchIds),
            'meta' => ['at' => BusinessTime::iso(now()), 'stale_after_seconds' => DriverPosition::staleAfter()]]);
    }

    // ── Branch staff app ───────────────────────────────────────────────────

    /** Counts for the staff home: new, preparing, ready, waiting for a driver, out for delivery, drivers on duty. */
    public function overview(Request $request): JsonResponse
    {
        $branch = $this->branchFor($request, 'orders.view');
        $f = OrderFulfillment::where('branch_id', $branch->id)->where('type', 'delivery');
        $count = fn (array $s) => (clone $f)->whereIn('status', $s)->count();
        $drivers = $this->dispatch->branchDrivers($branch);

        return response()->json(['data' => [
            'branch' => ['id' => $branch->id, 'name' => $branch->localized('name'), 'dispatch_mode' => $branch->dispatch_mode],
            'new' => $count(['pending']), 'preparing' => $count(['accepted', 'preparing']), 'ready' => $count(['ready']),
            'out_for_delivery' => $count(['dispatched', 'out_for_delivery']), 'failed' => $count(['failed']),
            'waiting_for_driver' => Delivery::where('branch_id', $branch->id)->where('status', 'pending')->count()
                + (clone $f)->where('status', 'ready')->whereDoesntHave('deliveries', fn ($q) => $q->whereNotIn('status', ['cancelled', 'failed', 'returned']))->count(),
            'drivers_on_duty' => $drivers->where('on_duty', true)->count(), 'drivers_total' => $drivers->count(),
        ]]);
    }

    /** Delivery orders of the branch by stage (new | preparing | ready | out | failed | all). A failed delivery stays visible so staff can bring it back. */
    public function queue(Request $request): JsonResponse
    {
        $branch = $this->branchFor($request, 'orders.view');
        $stages = ['new' => ['pending'], 'preparing' => ['accepted', 'preparing'], 'ready' => ['ready'], 'out' => ['dispatched', 'out_for_delivery'], 'failed' => ['failed']];
        $stage = (string) $request->query('stage', 'all');
        $rows = OrderFulfillment::with(['order', 'deliveries.driver:id,name', 'items'])->where('branch_id', $branch->id)->where('type', 'delivery')
            ->whereIn('status', $stages[$stage] ?? array_merge(...array_values($stages)))->orderBy('created_at')->limit(100)->get();

        return response()->json(['data' => $rows->map(function (OrderFulfillment $f) {
            $d = $f->activeDelivery();

            return [
                'fulfillment_id' => $f->id, 'status' => $f->status->value, 'number' => $f->order->number, 'placed_at' => BusinessTime::iso($f->order->placed_at),
                'customer_name' => $f->order->customer_name, 'customer_phone' => $f->order->customer_phone, 'address' => $f->order->address_snapshot,
                'items_count' => (int) $f->items->sum('quantity'), 'payment_method' => $f->order->payment_method, 'grand_total' => $f->order->grand_total,
                'scheduled_for' => BusinessTime::iso($f->slot_date ?? null),
                'delivery' => $d ? ['id' => $d->id, 'status' => $d->status->value, 'driver' => $d->driver ? ['id' => $d->driver->id, 'name' => $d->driver->name] : null] : null,
            ];
        })->values()]);
    }

    public function drivers(Request $request): JsonResponse
    {
        $branch = $this->branchFor($request, 'deliveries.view');

        return response()->json(['data' => $this->dispatch->branchDrivers($branch)->values()]);
    }

    /** Assign (or re-assign) a driver to a ready delivery order; creates the delivery when needed. */
    public function assign(Request $request, OrderFulfillment $fulfillment): JsonResponse
    {
        $data = $request->validate(['driver_id' => 'required|uuid']);
        $this->authorizeBranch('deliveries.assign', $fulfillment->branch_id);
        $driver = User::findOrFail($data['driver_id']);
        $d = $this->dispatch->staffAssign($fulfillment, $driver, $this->user());

        return response()->json(['data' => ['delivery_id' => $d->id, 'status' => $d->status->value, 'driver' => ['id' => $driver->id, 'name' => $driver->name]]]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function assertDriver(User $u): void
    {
        if (! $this->dispatch->isDriver($u)) {
            throw DomainException::make('driver_not_eligible', 403);
        }
    }

    /** A position far outside Qatar (or the "no fix" pair 0,0) is a GPS glitch, not a delivery. */
    private function assertInServiceArea(?float $lat, ?float $lng): void
    {
        if ($lat === null || $lng === null) {
            return;
        }
        if (! Geo::validPoint($lat, $lng) || ! Geo::inBounds($lat, $lng, Geo::QATAR_BOUNDS, (float) config('platform.tracking.service_margin_deg', 1.0))) {
            throw DomainException::make('location_out_of_area', 422);
        }
    }

    /** The time the phone says it took the fix, refused when it is in the future or too old to be a live position. */
    private function fixTime(string $value): CarbonImmutable
    {
        $at = CarbonImmutable::parse($value);
        if ($at->greaterThan(now()->addSeconds((int) config('platform.tracking.future_skew_seconds', 60)))) {
            throw DomainException::make('location_in_future', 422);
        }
        if ($at->lessThan(now()->subMinutes((int) config('platform.tracking.max_fix_age_minutes', 10)))) {
            throw DomainException::make('location_too_old', 422);
        }

        return $at;
    }

    private function branchFor(Request $request, string $permission): Branch
    {
        $id = $request->validate(['branch_id' => 'nullable|uuid'])['branch_id'] ?? null;
        $scope = $this->scope($permission, $id);
        $branch = Branch::query()->when($id, fn ($q) => $q->whereKey($id), fn ($q) => $q->orderBy('sort_order'))
            ->when(! $scope->all, fn ($q) => $q->whereIn('id', $scope->branchIds))->first();
        abort_if(! $branch, 403, __('errors.forbidden'));

        return $branch;
    }

    /** @return array<string, mixed> */
    private function job(Delivery $d): array
    {
        $o = $d->fulfillment->order;

        return [
            'id' => $d->id, 'tracking_number' => $d->tracking_number, 'status' => $d->status->value, 'fulfillment_id' => $d->order_fulfillment_id,
            'order_number' => $o->number, 'customer_name' => $o->customer_name, 'customer_phone' => $o->customer_phone, 'address' => $o->address_snapshot,
            'payment_method' => $o->payment_method, 'cod_amount' => $d->cod_amount, 'requires_proof' => $d->requires_proof,
            'branch' => ['id' => $d->branch_id, 'name' => $d->fulfillment->branch?->localized('name')],
            'scheduled_for' => BusinessTime::iso($d->scheduled_for), 'assigned_at' => BusinessTime::iso($d->assigned_at),
        ];
    }
}
