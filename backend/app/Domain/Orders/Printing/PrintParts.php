<?php

namespace App\Domain\Orders\Printing;

use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Support\BusinessTime;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Which parts a print request covers, and whether the user may print them. Printing needs what viewing the order needs: `orders.view` at the
 * part's branch. A part the user named but may not see refuses the whole request (nothing is printed, nothing is logged); a batch chosen by a
 * rule ("everything waiting to be prepared", "today's deliveries of driver X") only ever covers the user's own branches.
 */
class PrintParts
{
    /**
     * Most parts one PDF holds. The PDF engine needs about a seventh of a second a page, so 50 parts are a few seconds and stay well inside a web request;
     * a longer list is printed in pieces (by branch, or by driver).
     */
    public const MAX_PARTS = 50;

    /** A part that is not yet ready: it still has to be gathered. */
    public const TO_PREPARE = ['pending', 'accepted', 'preparing'];

    /** A delivery that still has to reach the customer. */
    public const OPEN_DELIVERY = ['pending', 'assigned', 'accepted', 'picked_up', 'out_for_delivery', 'rescheduled'];

    private const RELATIONS = ['order', 'branch', 'items', 'slot', 'deliveries'];

    public function __construct(private readonly AccessControl $acl) {}

    /**
     * Parts the user chose by id, in the order given.
     *
     * @param  list<string>  $ids
     * @return Collection<int, OrderFulfillment>
     *
     * @throws ModelNotFoundException an id is not a part
     * @throws AuthorizationException a part is at a branch the user may not see
     */
    public function named(User $user, PrintKind $kind, array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($id) => is_string($id) ? strtolower(trim($id)) : '', $ids), fn (string $id) => Str::isUuid($id))));
        if ($ids === []) {
            throw new ModelNotFoundException;
        }
        if (count($ids) > self::MAX_PARTS) {
            throw new UnprocessableEntityHttpException(__('print_docs.errors.too_many', ['max' => self::MAX_PARTS]));
        }
        $parts = $this->load(OrderFulfillment::query()->whereIn('id', $ids))->sortBy(fn (OrderFulfillment $p) => array_search($p->id, $ids, true))->values();
        if ($parts->count() !== count($ids)) {
            throw new ModelNotFoundException;
        }
        foreach ($parts as $part) {
            if (! $this->acl->allows($user, 'orders.view', $part->branch_id)) {
                throw new AuthorizationException(__('errors.forbidden'));
            }
            if ($kind === PrintKind::Note && $part->type !== 'delivery') {
                throw new NotFoundHttpException(__('print_docs.errors.not_a_delivery'));
            }
            if ($part->status->value === 'cancelled') {
                throw new UnprocessableEntityHttpException(__('print_docs.errors.cancelled'));
            }
        }

        return $parts;
    }

    /**
     * Everything at the user's branches (or one of them) that is waiting to be prepared, oldest promise first. Unpaid online orders are not waiting yet.
     * `$type` narrows to `delivery` or `pickup` parts.
     *
     * @return Collection<int, OrderFulfillment>
     */
    public function waitingToPrepare(User $user, ?string $branchId = null, ?string $type = null): Collection
    {
        return $this->load($this->waitingQuery($user, $branchId, $type)->orderByRaw('slot_date IS NULL')->orderBy('slot_date')->orderBy('created_at')->orderBy('id')->limit(self::MAX_PARTS));
    }

    /**
     * The delivery parts of one day, in the order the driver meets them (the time of the delivery). `$driver` is a driver's id, `none` for the deliveries
     * nobody has taken yet, or null for everyone. Today also carries what is still open from earlier days and what has no time (the driver's "my day").
     *
     * @return Collection<int, OrderFulfillment>
     */
    public function deliveriesOn(User $user, string $date, ?string $driver = null, ?string $branchId = null): Collection
    {
        $ids = $this->deliveryQuery($user, $date, $driver, $branchId)->orderByRaw('scheduled_for IS NULL')->orderBy('scheduled_for')->orderBy('created_at')->orderBy('id')
            ->limit(self::MAX_PARTS)->pluck('order_fulfillment_id')->unique()->values()->all();

        return $this->load(OrderFulfillment::query()->whereIn('id', $ids))->sortBy(fn (OrderFulfillment $p) => array_search($p->id, $ids, true))->values();
    }

    /** How many parts "waiting to be prepared" would print, without loading them. */
    public function countWaiting(User $user, ?string $branchId = null, ?string $type = null): int
    {
        return $this->waitingQuery($user, $branchId, $type)->count();
    }

    /** @return Builder<OrderFulfillment> */
    private function waitingQuery(User $user, ?string $branchId, ?string $type): Builder
    {
        return $this->scoped(OrderFulfillment::query()->whereIn('status', self::TO_PREPARE)->whereHas('order', fn ($o) => $o->where('status', '!=', 'pending_payment'))
            ->when(in_array($type, ['delivery', 'pickup'], true), fn ($q) => $q->where('type', $type)), $user, $branchId);
    }

    public function countDeliveries(User $user, string $date, ?string $driver = null, ?string $branchId = null): int
    {
        return $this->deliveryQuery($user, $date, $driver, $branchId)->count();
    }

    /**
     * The drivers with open deliveries on a day inside the user's branches, with how many each has: id => [name, count]. Deliveries nobody took are under `none`.
     *
     * @return list<array{id: string, name: ?string, count: int}>
     */
    public function driversOn(User $user, string $date, ?string $branchId = null): array
    {
        return $this->deliveryQuery($user, $date, null, $branchId)->leftJoin('users as drv', 'drv.id', '=', 'deliveries.driver_id')
            ->groupBy('deliveries.driver_id', 'drv.name')->orderByRaw('drv.name IS NULL')->orderBy('drv.name')->orderBy('deliveries.driver_id')
            ->selectRaw('deliveries.driver_id, drv.name, count(*) as n')->toBase()->get()
            ->map(fn ($r) => ['id' => $r->driver_id ?? 'none', 'name' => $r->name, 'count' => (int) $r->n])->all();
    }

    /** @return Builder<Delivery> */
    private function deliveryQuery(User $user, string $date, ?string $driver, ?string $branchId): Builder
    {
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && strtotime($date) !== false ? $date : BusinessTime::now()->toDateString();
        [$start, $end] = BusinessTime::dateRange($parsed, $parsed);
        $today = $parsed === BusinessTime::now()->toDateString();

        $q = Delivery::query()->whereIn('deliveries.status', self::OPEN_DELIVERY)
            ->where(fn ($w) => $w->whereBetween('deliveries.scheduled_for', [$start, $end])
                ->when($today, fn ($t) => $t->orWhereNull('deliveries.scheduled_for')->orWhere('deliveries.scheduled_for', '<', $start)))
            ->whereHas('fulfillment', fn ($f) => $f->where('type', 'delivery')->where('status', '!=', 'cancelled'));
        $scope = $this->acl->scope($user, 'orders.view')->narrow($branchId);
        $scope->apply($q->getQuery(), 'deliveries.branch_id');
        if ($driver === 'none') {
            $q->whereNull('deliveries.driver_id');
        } elseif ($driver !== null && $driver !== '' && Str::isUuid($driver)) {
            $q->where('deliveries.driver_id', strtolower($driver));
        }

        return $q;
    }

    /**
     * @param  Builder<OrderFulfillment>  $q
     * @return Builder<OrderFulfillment>
     */
    private function scoped(Builder $q, User $user, ?string $branchId): Builder
    {
        $this->acl->scope($user, 'orders.view')->narrow($branchId)->apply($q->getQuery(), 'branch_id');

        return $q;
    }

    /**
     * @param  Builder<OrderFulfillment>  $q
     * @return Collection<int, OrderFulfillment>
     */
    private function load(Builder $q): Collection
    {
        return $q->with(self::RELATIONS)->get();
    }
}
