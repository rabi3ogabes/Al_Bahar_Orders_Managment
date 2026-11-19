<?php

namespace App\Support;

use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Operations\Services\OpsClock;
use App\Domain\Operations\Support\OpsAge;
use App\Domain\Orders\Models\OrderCancellation;
use App\Domain\Orders\Models\OrderFulfillment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What the branch staff app's queue and overview add on top of the plain order rows (docs/api/STAFF_QUEUE.md):
 *  - `clock`: how long the part has waited and whether that is fine, getting late or late (the order clock the dashboard uses);
 *  - `cancellation_request`: the customer's open request to cancel, which blocks the hand-over until a manager decides;
 *  - `payment_status` / `amount_due`: what is still to be collected at the counter;
 *  - `pickup`: the state of the pickup pass of a pickup part;
 *  - for the overview: the branches the person works in, the pickup counts and what they may do at THIS branch.
 *
 * Pure reads. One query for the open cancellation requests of the whole page, the branch hours are loaded once by the clock.
 */
final class StaffQueue
{
    /** What the app asks about for the selected branch (the answers are per branch, a flat permission list cannot say). */
    public const PERMISSIONS = ['orders.edit', 'orders.approve', 'deliveries.assign', 'deliveries.edit', 'pickup.view', 'pickup.edit', 'pickup.approve', 'payments.create'];

    public function __construct(private readonly OpsClock $clock, private readonly AccessControl $acl) {}

    /**
     * The extra fields of each part of a page of the queue, by part id. Load `order`, `deliveries` and `pickup` on the parts first.
     *
     * @param  Collection<int, OrderFulfillment>  $parts
     * @return array<string, array<string, mixed>>
     */
    public function extras(Collection $parts): array
    {
        if ($parts->isEmpty()) {
            return [];
        }
        $this->clock->preload($parts->pluck('branch_id')->unique()->values()->all());
        $requests = OrderCancellation::query()->where('status', 'requested')->whereIn('order_id', $parts->pluck('order_id')->unique()->values()->all())
            ->orderByDesc('created_at')->get(['id', 'order_id', 'status', 'reason', 'created_at'])->unique('order_id')->keyBy('order_id');

        $out = [];
        foreach ($parts as $f) {
            $order = $f->order;
            $request = $requests->get($f->order_id);
            $due = Money::of($order->grand_total)->minus(Money::of($order->paid_total));
            $out[$f->id] = [
                'clock' => $this->clockOf($f, $request),
                'cancellation_request' => $request === null ? null : [
                    'id' => $request->id, 'status' => $request->status, 'reason' => $request->reason ? Str::limit($request->reason, 200) : null,
                    'requested_at' => BusinessTime::iso($request->created_at),
                ],
                'payment_status' => $order->payment_status->value,
                'amount_due' => $due->isNegative() ? '0.00' : $due->toDecimal(),
            ] + ($f->type === 'pickup' ? ['pickup' => $this->passOf($f)] : []);
        }

        return $out;
    }

    /**
     * The order clock of one part: what it is waiting for and for how long. A part nobody has to act on (a driver has it, it is out) still
     * shows how long since it last moved, never amber or red, so every card can carry an age.
     *
     * @return array{state: string, age_minutes: int, label: string, kind: string, wall_minutes: int, paused: bool}
     */
    private function clockOf(OrderFulfillment $f, ?OrderCancellation $request): array
    {
        $age = $this->clock->forFulfillment($f);
        $kind = null;
        if ($age === null && $request !== null) {
            // A pending part whose order the customer asked to cancel is waiting for a manager: that wait is the request's own.
            $age = $this->clock->evaluate(OpsClock::PENDING, $request->created_at ?? CarbonImmutable::now(), $f->branch_id);
            $kind = 'cancellation';
        }
        if ($age === null) {
            $since = $f->updated_at ?? $f->created_at ?? CarbonImmutable::now();
            $minutes = max(0, intdiv(CarbonImmutable::now()->getTimestamp() - $since->getTimestamp(), 60));

            return ['state' => OpsAge::OK, 'age_minutes' => $minutes, 'label' => OpsClock::label($minutes), 'kind' => 'none', 'wall_minutes' => $minutes, 'paused' => false];
        }

        return ['state' => $age->state, 'age_minutes' => $age->ageMinutes, 'label' => $age->label, 'kind' => $kind ?? $age->kind, 'wall_minutes' => $age->wallMinutes, 'paused' => $age->paused];
    }

    /** @return array{status: string|null, token_expires_at: string|null, expired: bool} */
    private function passOf(OrderFulfillment $f): array
    {
        $pass = $f->pickup;
        $expires = $pass?->token_expires_at;

        return ['status' => $pass?->status, 'token_expires_at' => BusinessTime::iso($expires), 'expired' => $expires !== null && $expires->isPast()];
    }

    /**
     * The branches this person may see orders of (for the branch picker of a manager of several), the selected one first by sort order.
     *
     * @return list<array{id: string, name: string}>
     */
    public function branches(User $user): array
    {
        $scope = $this->acl->scope($user, 'orders.view');
        if ($scope->isEmpty()) {
            return [];
        }

        return Branch::query()->when(! $scope->all, fn ($q) => $q->whereIn('id', $scope->branchIds))->orderBy('sort_order')->limit(100)->get(['id', 'name_en', 'name_ar', 'sort_order'])
            ->map(fn (Branch $b) => ['id' => $b->id, 'name' => (string) $b->localized('name')])->values()->all();
    }

    /**
     * What this person may do at this branch, by permission key: the app shows or hides the buttons by it (the server checks every request again).
     *
     * @return array<string, bool>
     */
    public function can(User $user, string $branchId): array
    {
        $out = [];
        foreach (self::PERMISSIONS as $permission) {
            $out[$permission] = $this->acl->allows($user, $permission, $branchId);
        }

        return $out;
    }
}
