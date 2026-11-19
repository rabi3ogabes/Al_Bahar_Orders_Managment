<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DeliveryDecline;
use App\Domain\Fulfillment\Services\DeliveryService;
use App\Domain\Fulfillment\Services\DispatchService;
use App\Domain\Fulfillment\Services\DriverGps;
use App\Domain\Identity\Models\User;
use App\Domain\Operations\Services\OpsClock;
use App\Domain\Payments\Models\CashCollection;
use App\Domain\Payments\Services\CashDrawerService;
use App\Http\Controllers\Api\V1\ApiController;
use App\Support\BusinessTime;
use App\Support\DomainException;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The driver's own day (prefix /api/v1/admin): what he did today, the cash he still holds, and the steps that belong to him alone.
 *
 *  - GET  dispatch/driver/day            today's jobs (done, failed, declined, active), the cash collected today, the cash still in his pocket
 *                                        with how late it is, and when he went on duty. Needs only his own sign-in, never the finance right.
 *  - POST deliveries/{id}/arrived        "I have arrived": the customer is told once.
 *  - POST dispatch/jobs/{id}/decline     hand back a job he was given and has not accepted.
 *
 * @tags Dispatch
 */
class DriverDayController extends ApiController
{
    /** Statuses in which a job is still the driver's to work. */
    private const OPEN = ['assigned', 'accepted', 'picked_up', 'out_for_delivery', 'rescheduled', 'failed'];

    /** Statuses of a job whose delivery did not work today (it is not "done" and it is not waiting any more). */
    private const FAILED = ['failed', 'rescheduled', 'returning', 'returned'];

    public function __construct(private readonly DispatchService $dispatch, private readonly DeliveryService $deliveries) {}

    public function day(CashDrawerService $drawer, OpsClock $clock): JsonResponse
    {
        $u = $this->user();
        $this->assertDriver($u);
        $profile = $this->dispatch->profile($u);
        $start = BusinessTime::now()->startOfDay()->utc();
        $mine = fn () => Delivery::query()->where('driver_id', $u->id);

        $rows = $mine()->with('fulfillment.order:id,number,customer_name,address_snapshot,payment_method')
            ->where(fn ($q) => $q->whereIn('status', self::OPEN)->orWhere('delivered_at', '>=', $start)->orWhere('failed_at', '>=', $start))
            ->orderByRaw('coalesce(delivered_at, failed_at, assigned_at, created_at) desc')->limit(60)->get();
        $declines = DeliveryDecline::query()->with('delivery.fulfillment.order:id,number,customer_name,address_snapshot,payment_method')
            ->where('driver_id', $u->id)->whereIn('reason', DeliveryDecline::DRIVER_REASONS)->where('created_at', '>=', $start)->orderByDesc('created_at')->limit(30)->get();

        $list = $rows->map(fn (Delivery $d) => $this->row($d, $d->status->value, $d->delivered_at ?? $d->failed_at ?? $d->assigned_at ?? $d->created_at))
            ->concat($declines->filter(fn (DeliveryDecline $x) => $x->delivery !== null)->map(fn (DeliveryDecline $x) => $this->row($x->delivery, 'declined', $x->created_at)))
            ->sortByDesc(fn (array $r) => (string) $r['at'])->values();

        return response()->json(['data' => [
            'date' => BusinessTime::now()->toDateString(),
            'on_duty' => (bool) $profile->on_duty,
            'on_duty_since' => BusinessTime::iso($profile->on_duty ? $profile->on_duty_since : null),
            'jobs' => [
                'done' => $mine()->where('status', 'delivered')->where('delivered_at', '>=', $start)->count(),
                'failed' => $mine()->whereIn('status', self::FAILED)->where('failed_at', '>=', $start)->count(),
                'declined' => $declines->count(),
                'active' => $mine()->whereIn('status', self::OPEN)->count(),
            ],
            'cash' => $this->cash($u, $start, $drawer, $clock),
            'list' => $list->all(),
            'at' => BusinessTime::iso(now()),
        ]]);
    }

    /**
     * "I have arrived". Only the driver of the delivery, only while it is out for delivery; repeating it changes nothing. The customer is told
     * once. The driver's position is optional (a lift lobby has no GPS) and is kept in the delivery's history when it comes.
     */
    public function arrived(Request $request, Delivery $delivery): JsonResponse
    {
        $this->assertDriver($this->user());
        $d = $this->deliveries->arrived($delivery, $this->user(), DriverGps::from($request));

        return response()->json(['data' => ['id' => $d->id, 'status' => $d->status->value, 'arrived_at' => BusinessTime::iso($d->arrived_at)]]);
    }

    /**
     * Hand back a job that was given to him and that he has not accepted: `too_far`, `vehicle`, `shift_ending` or `other` (a short note is welcome).
     * The job goes back to waiting (or straight to the next driver in a branch that gives jobs out by itself, never to him again), the branch
     * managers are told, and it is written to the delivery's history and the audit log. A job he accepted cannot be handed back this way (409).
     */
    public function decline(Request $request, Delivery $delivery): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|in:'.implode(',', DeliveryDecline::DRIVER_REASONS), 'note' => 'nullable|string|max:300']);
        $this->assertDriver($this->user());
        $d = $this->dispatch->declineJob($delivery, $this->user(), $data['reason'], $data['note'] ?? null);

        return response()->json(['data' => ['id' => $d->id, 'status' => $d->status->value, 'declined' => true]]);
    }

    /**
     * Cash collected today, and cash still held (collected, not yet handed in to a cashier): how much, since when, and how late that is
     * (the order clock's cash limits: ok, amber, red).
     *
     * @return array<string, mixed>
     */
    private function cash(User $u, CarbonImmutable $start, CashDrawerService $drawer, OpsClock $clock): array
    {
        $today = CashCollection::query()->where('collected_by', $u->id)->where('tender', 'cash')->whereIn('status', ['collected', 'partial'])->where('collected_at', '>=', $start)->sum('amount_collected');
        $held = Money::zero();
        $count = 0;
        $oldest = null;
        foreach ($drawer->balancesFor($u) as $b) {
            $held = $held->plus($b['amount']);
            $count += $b['count'];
            $at = CarbonImmutable::parse((string) $b['oldest']);
            $oldest = $oldest === null || $at->lessThan($oldest) ? $at : $oldest;
        }
        $age = $oldest !== null ? $clock->evaluate(OpsClock::CASH, $oldest) : null;

        return [
            'currency' => 'QAR',
            'collected_today' => Money::of((string) $today)->toDecimal(),
            'held' => $held->toDecimal(), 'held_count' => $count, 'oldest_at' => BusinessTime::iso($oldest),
            'state' => $age?->state ?? 'ok', 'age_minutes' => $age?->ageMinutes,
        ];
    }

    /**
     * One line of the day's list: enough to recognise the job (number, name, area, time, cash), nothing a hand-in needs.
     *
     * @return array<string, mixed>
     */
    private function row(Delivery $d, string $status, mixed $at): array
    {
        $order = $d->fulfillment?->order;
        $a = (array) ($order?->address_snapshot ?? []);

        return [
            'id' => $d->id, 'order_number' => $order?->number, 'status' => $status, 'customer_name' => $order?->customer_name,
            'address' => array_filter(['zone_number' => $a['zone_number'] ?? null, 'area' => $a['area'] ?? null], fn ($v) => $v !== null && $v !== ''),
            'cod_amount' => $order?->payment_method === 'cod' ? $d->cod_amount : '0.00', 'at' => BusinessTime::iso($at),
        ];
    }

    private function assertDriver(User $u): void
    {
        if (! $this->dispatch->isDriver($u)) {
            throw DomainException::make('driver_not_eligible', 403);
        }
    }
}
