<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Branches\Models\Branch;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Services\DispatchService;
use App\Domain\Fulfillment\Services\DriverJobPresenter;
use App\Domain\Fulfillment\Services\DriverPosition;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Enums\FulfillmentStatus as FS;
use App\Domain\Orders\Enums\OrderStatus;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Models\OrderItem;
use App\Http\Controllers\Api\V1\ApiController;
use App\Support\AlternatePhone;
use App\Support\BusinessTime;
use App\Support\DomainException;
use App\Support\Geo;
use App\Support\StaffQueue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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
    public function __construct(private readonly DispatchService $dispatch, private readonly DriverJobPresenter $jobs) {}

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

    /** My jobs: scope=active (default) or history (last 30 finished; see {@see history()}). */
    public function jobs(Request $request): JsonResponse
    {
        $u = $this->user();
        $this->assertDriver($u);
        if ($request->query('scope') === 'history') {
            return $this->history();
        }
        $rows = $this->withParcel(Delivery::query())->where('driver_id', $u->id)->whereNotIn('status', ['delivered', 'returned', 'cancelled'])
            ->orderBy('scheduled_for')->orderBy('assigned_at')->get();

        return response()->json(['data' => $this->jobs->many($rows)]);
    }

    /**
     * My last 30 finished jobs, newest first. The short version of a job (order number, area, time, status, amount, items); a job finished more
     * than 24 hours ago no longer carries the customer's phone numbers or street (`redacted: true`).
     */
    public function history(): JsonResponse
    {
        $u = $this->user();
        $this->assertDriver($u);
        $rows = $this->withParcel(Delivery::query())->where('driver_id', $u->id)->whereIn('status', ['delivered', 'returned', 'cancelled'])->orderByDesc('updated_at')->limit(30)->get();

        return response()->json(['data' => $this->jobs->many($rows)]);
    }

    /** One of my jobs in full (opened from a notification, or to refresh an open window). 404 when it is not mine (any more). */
    public function job(Delivery $delivery): JsonResponse
    {
        $u = $this->user();
        $this->assertDriver($u);
        abort_unless($delivery->driver_id === $u->id, 404, __('driver_day.errors.job_not_found'));
        $delivery->load(['fulfillment.order', 'fulfillment.branch', 'fulfillment.items']);

        return response()->json(['data' => $this->jobs->job($delivery)]);
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

    /**
     * Counts for the staff home: new, preparing, ready, waiting for a driver, out for delivery, drivers on duty. Besides the delivery counts (unchanged) it
     * carries the pickup counts (`pickup`), the branches the person works in (`branches`, for the branch picker of a manager of several) and what they
     * may do at this branch (`can`). See docs/api/STAFF_QUEUE.md.
     */
    public function overview(Request $request, StaffQueue $staff): JsonResponse
    {
        $branch = $this->branchFor($request, 'orders.view');
        $includePickup = $request->boolean('include_pickup');
        $f = $this->parts($branch, $includePickup);
        $count = fn (array $s) => (clone $f)->whereIn('status', $s)->count();
        $pickups = $this->parts($branch, true, 'pickup');
        $pickupCount = fn (array $s) => (clone $pickups)->whereIn('status', $s)->count();
        $drivers = $this->dispatch->branchDrivers($branch);

        return response()->json(['data' => [
            'branch' => ['id' => $branch->id, 'name' => $branch->localized('name'), 'dispatch_mode' => $branch->dispatch_mode],
            'new' => $count(['pending']), 'preparing' => $count(['accepted', 'preparing']), 'ready' => $count($includePickup ? ['ready', 'pickup_ready'] : ['ready']),
            'out_for_delivery' => $count(['dispatched', 'out_for_delivery']), 'failed' => $count(['failed']),
            'waiting_for_driver' => Delivery::where('branch_id', $branch->id)->where('status', 'pending')->count()
                + (clone $f)->where('status', 'ready')->where('type', 'delivery')->whereDoesntHave('deliveries', fn ($q) => $q->whereNotIn('status', ['cancelled', 'failed', 'returned']))->count(),
            'drivers_on_duty' => $drivers->where('on_duty', true)->count(), 'drivers_total' => $drivers->count(),
            'pickup' => ['new' => $pickupCount(['pending']), 'preparing' => $pickupCount(['accepted', 'preparing']), 'ready' => $pickupCount(['pickup_ready'])],
            'branches' => $staff->branches($this->user()),
            'can' => $staff->can($this->user(), $branch->id),
        ]]);
    }

    /**
     * Delivery orders of the branch by stage (new | preparing | ready | out | failed | all). A failed delivery stays visible so staff can bring it back.
     * An online order that is not paid yet is left out (it cannot be accepted: the dashboard hides it the same way). Every row carries its `type`
     * (`delivery`); with `include_pickup=1` the branch's pickup parts are listed too (type `pickup`; `ready` then also means `pickup_ready`), and without
     * the flag the answer is exactly what it always was. `type=delivery|pickup` narrows the list to one kind (pickup implies the flag).
     *
     * Every row also carries its order clock, the customer's open cancellation request and what is left to pay (docs/api/STAFF_QUEUE.md). `page` / `per_page`
     * (1-100, default 50) page the list; `meta` says whether `next` exists. A caller that sends neither gets the first 100 rows as before.
     */
    public function queue(Request $request, StaffQueue $staff): JsonResponse
    {
        $branch = $this->branchFor($request, 'orders.view');
        $v = $request->validate(['type' => 'nullable|in:delivery,pickup', 'page' => 'nullable|integer|min:1|max:10000', 'per_page' => 'nullable|integer|min:1|max:100']);
        $type = $v['type'] ?? null;
        $includePickup = $request->boolean('include_pickup') || $type === 'pickup';
        $stages = ['new' => ['pending'], 'preparing' => ['accepted', 'preparing'], 'ready' => $includePickup ? ['ready', 'pickup_ready'] : ['ready'], 'out' => ['dispatched', 'out_for_delivery'], 'failed' => ['failed']];
        $stage = (string) $request->query('stage', 'all');
        $paged = isset($v['page']) || isset($v['per_page']);
        $perPage = $paged ? (int) ($v['per_page'] ?? 50) : 100;
        $page = (int) ($v['page'] ?? 1);
        $rows = $this->parts($branch, $includePickup, $type)->with(['order', 'deliveries.driver:id,name', 'items', 'pickup'])
            ->whereIn('status', $stages[$stage] ?? array_merge(...array_values($stages)))
            // The longest wait first: a new part from when it was placed, a part being prepared from when it was accepted, a ready one from when it became ready.
            ->orderByRaw("coalesce(case when order_fulfillments.status in ('accepted', 'preparing') then order_fulfillments.accepted_at when order_fulfillments.status in ('ready', 'pickup_ready') then order_fulfillments.ready_at end, order_fulfillments.created_at)")
            ->orderBy('order_fulfillments.created_at')->orderBy('order_fulfillments.id')->offset(($page - 1) * $perPage)->limit($perPage + 1)->get();
        $more = $rows->count() > $perPage;
        $rows = $rows->take($perPage);
        $extras = $staff->extras($rows);

        return response()->json(['data' => $rows->map(function (OrderFulfillment $f) use ($extras) {
            $d = $f->activeDelivery();

            return [
                'fulfillment_id' => $f->id, 'order_id' => $f->order_id, 'type' => $f->type, 'seq' => (int) $f->order->progress_seq, 'status' => $f->status->value, 'number' => $f->order->number, 'placed_at' => BusinessTime::iso($f->order->placed_at),
                'customer_name' => $f->order->customer_name, 'customer_phone' => $f->order->customer_phone, 'alternate_phone' => AlternatePhone::visible($f->order->alternate_phone),
                'address' => $f->order->address_snapshot,
                // Only what is still to be prepared and delivered (lines the branch removed or swapped out are not listed); the counters tell the app the order was changed.
                'items_count' => (int) $f->items->sum(fn ($i) => max(0, (int) $i->quantity - (int) $i->quantity_cancelled)), 'items' => $this->itemRows($f->items),
                'was_adjusted' => $f->order->wasAdjusted(), 'adjustment_count' => (int) $f->order->adjustment_count, 'can_adjust' => in_array($f->status, [FS::Accepted, FS::Preparing], true),
                'payment_method' => $f->order->payment_method, 'grand_total' => $f->order->grand_total,
                'scheduled_for' => BusinessTime::iso($f->slot_date ?? null),
                'delivery' => $d ? ['id' => $d->id, 'status' => $d->status->value, 'driver' => $d->driver ? ['id' => $d->driver->id, 'name' => $d->driver->name] : null] : null,
            ] + $extras[$f->id];
        })->values(), 'meta' => ['page' => $page, 'per_page' => $perPage, 'has_more' => $more, 'next' => $more ? $page + 1 : null]]);
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

    /**
     * The branch's parts the staff app works on: deliveries (and pickups when asked, or only pickups with `$type = 'pickup'`), leaving out orders still
     * waiting for their online payment.
     *
     * @return Builder<OrderFulfillment>
     */
    private function parts(Branch $branch, bool $includePickup, ?string $type = null): Builder
    {
        $types = $type !== null ? [$type] : ($includePickup ? ['delivery', 'pickup'] : ['delivery']);

        return OrderFulfillment::query()->where('branch_id', $branch->id)->whereIn('type', $types)
            ->whereHas('order', fn ($o) => $o->where('status', '!=', OrderStatus::PendingPayment->value));
    }

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

    /**
     * @param  Builder<Delivery>  $query
     * @return Builder<Delivery>
     */
    private function withParcel(Builder $query): Builder
    {
        return $query->with(['fulfillment.order', 'fulfillment.branch', 'fulfillment.items']);
    }

    /**
     * What is in the parcel, for the driver's and the branch staff's order windows: name, variant, SKU and the quantity still to deliver
     * (cancelled units and lines the branch removed or swapped out left out). `order_item_id` is the line's id for the adjustments API (same value as `id`);
     * `adjustment` says what the branch did to the line (null: nothing). No prices: they are not the driver's business.
     *
     * @param  Collection<int, OrderItem>  $items
     * @return list<array{id: string, order_item_id: string, name: string, variant_name: ?string, sku: ?string, quantity: int, adjustment: ?array{kind: string, original_quantity: int}}>
     */
    private function itemRows($items): array
    {
        return $items->map(fn ($i) => ['id' => $i->id, 'order_item_id' => $i->id, 'name' => $i->name(), 'variant_name' => $i->variantName(), 'sku' => $i->sku,
            'quantity' => max(0, (int) $i->quantity - (int) $i->quantity_cancelled),
            'adjustment' => $i->adjustment_kind ? ['kind' => $i->adjustment_kind, 'original_quantity' => (int) ($i->original_quantity ?? $i->quantity)] : null])
            ->filter(fn (array $r) => $r['quantity'] > 0)->values()->all();
    }
}
