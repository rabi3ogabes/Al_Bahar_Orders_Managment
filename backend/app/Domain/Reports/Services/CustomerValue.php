<?php

namespace App\Domain\Reports\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Support\BranchScope;
use App\Domain\Messaging\Services\CustomerFilter;
use App\Domain\Settings\Services\Settings;
use App\Support\BusinessTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What each customer is worth, and who has gone quiet (docs/guides/OWNER_PACK_AND_CUSTOMER_VALUE.md).
 *
 *  - The numbers come from {@see SalesBasis}, the same definition as the dashboard: what the customer was CHARGED for orders that count as sales, the
 *    branch's own share of a split order. They are worked out once, in the background (customers:refresh-value, every 30 minutes) and on demand when
 *    nobody has done it for half an hour, into `customer_value_rollups`, so a page of the list never scans the orders.
 *  - BRANCH SCOPE: a person limited to some branches reads only the rows of THOSE branches. They see what the customer spent at their branches, never
 *    what was spent elsewhere. A person who sees every branch reads the company rows. The customer's own page and the customers list work the numbers
 *    out live for the few customers on screen ({@see self::lifetime()}), with the same rule.
 *  - GONE QUIET: the customer has had at least one order reach them (delivered or collected), is still an active account, and has placed nothing since
 *    N days (setting customer_value.gone_quiet_days, 60 by default). Their usual gap between orders is the time between their first and their last order
 *    divided by the number of gaps; the "late by" figure is how many usual gaps have passed since the last order.
 */
final class CustomerValue
{
    public const QUIET_DEFAULT = 60;

    public const QUIET_MIN = 14;

    public const QUIET_MAX = 730;

    /** A page that finds the rollup older than this (the scheduler keeps it far younger) rebuilds it itself, so a stopped scheduler never leaves the lists stale for days. */
    public const STALE_SECONDS = 21600;

    public const TABS = ['top', 'repeat', 'one_time', 'new', 'quiet'];

    public const SORTS = ['spend', 'orders', 'basket', 'last_order', 'quiet_days', 'overdue'];

    /** The most rows a CSV export holds. */
    public const EXPORT_CAP = 5000;

    public function __construct(private readonly Settings $settings, private readonly AccessControl $access) {}

    public function quietDays(): int
    {
        $v = $this->settings->get('customer_value.gone_quiet_days', self::QUIET_DEFAULT);

        return is_numeric($v) && (int) $v >= self::QUIET_MIN && (int) $v <= self::QUIET_MAX ? (int) $v : self::QUIET_DEFAULT;
    }

    // ── the rollup ───────────────────────────────────────────────────────────

    /** Rebuilds the rollup when it has never been built or is very old; otherwise one cheap read. The scheduler normally keeps it fresh. */
    public function ensureFresh(): void
    {
        $at = $this->refreshedAt();
        if ($at === null || $at < CarbonImmutable::now()->subSeconds(self::STALE_SECONDS)) {
            $this->refresh();
        }
    }

    public function refreshedAt(): ?CarbonImmutable
    {
        $at = DB::table('customer_value_rollups')->max('refreshed_at');

        return $at !== null ? CarbonImmutable::parse($at) : null;
    }

    /**
     * Rebuilds every row in one pass: an upsert of every (customer, scope) that has orders, then the rows that were not touched (a customer all of whose
     * orders were cancelled since) are removed. One run at a time (a lock), and running it twice in a row gives the same rows: safe to repeat.
     *
     * @return array{rows: int, skipped: bool}
     */
    public function refresh(): array
    {
        $lock = Cache::lock('customer_value:refresh', 300);
        if (! $lock->get()) {
            return ['rows' => 0, 'skipped' => true];
        }
        try {
            $now = CarbonImmutable::now()->format('Y-m-d H:i:s.u'); // microseconds, so two runs a moment apart are told apart
            $shares = SalesBasis::orderShares()->whereNotNull('s.user_id')->join('order_fulfillments as pf', 'pf.id', '=', 's.fulfillment_id');
            $measures = "count(distinct s.order_id) as orders, count(distinct s.order_id) filter (where pf.status in ('delivered', 'picked_up')) as delivered_orders,
                coalesce(sum(s.share), 0)::numeric(14,2) as spend, min(s.placed_at) as first_order_at, max(s.placed_at) as last_order_at";
            $perBranch = (clone $shares)->selectRaw("s.user_id, s.branch_id::text as scope, {$measures}")->groupBy('s.user_id', 's.branch_id');
            $company = (clone $shares)->selectRaw("s.user_id, 'all' as scope, {$measures}")->groupBy('s.user_id');

            DB::transaction(function () use ($now, $perBranch, $company) {
                foreach ([$perBranch, $company] as $query) {
                    DB::insert('insert into customer_value_rollups (user_id, scope, orders, delivered_orders, spend, first_order_at, last_order_at, refreshed_at)
                        select q.user_id, q.scope, q.orders, q.delivered_orders, q.spend, q.first_order_at, q.last_order_at, ? from ('.$query->toSql().') q
                        on conflict (user_id, scope) do update set orders = excluded.orders, delivered_orders = excluded.delivered_orders, spend = excluded.spend,
                            first_order_at = excluded.first_order_at, last_order_at = excluded.last_order_at, refreshed_at = excluded.refreshed_at',
                        [$now, ...$query->getBindings()]);
                }
                DB::table('customer_value_rollups')->where('refreshed_at', '<', $now)->delete();
            });

            return ['rows' => DB::table('customer_value_rollups')->count(), 'skipped' => false];
        } finally {
            $lock->release();
        }
    }

    // ── what a viewer reads ──────────────────────────────────────────────────

    /** The branches the viewer's customer numbers are about: the customers right ({@see CustomerScope}), nothing wider; a branch filter can only narrow it. */
    public function scopeFor(User $viewer, ?string $branchId = null): BranchScope
    {
        return $this->access->scope($viewer, 'customers.view')->narrow($branchId);
    }

    /**
     * One row per customer in the viewer's scope: user_id, orders, delivered_orders, spend, first_order_at, last_order_at, days_quiet, avg_gap_days.
     * A viewer of every branch reads the company rows; a branch-limited viewer reads (and adds up) the rows of their own branches only.
     */
    private function base(BranchScope $scope, CarbonImmutable $now): Builder
    {
        $rows = DB::table('customer_value_rollups as r');
        if ($scope->all) {
            $rows->where('r.scope', 'all')->selectRaw('r.user_id, r.orders, r.delivered_orders, r.spend, r.first_order_at, r.last_order_at');
        } elseif ($scope->branchIds === []) {
            $rows->whereRaw('1 = 0')->selectRaw('r.user_id, r.orders, r.delivered_orders, r.spend, r.first_order_at, r.last_order_at');
        } else {
            $rows->whereIn('r.scope', $scope->branchIds)->groupBy('r.user_id')
                ->selectRaw('r.user_id, sum(r.orders) as orders, sum(r.delivered_orders) as delivered_orders, sum(r.spend) as spend, min(r.first_order_at) as first_order_at, max(r.last_order_at) as last_order_at');
        }

        return DB::query()->fromSub($rows, 'v')->selectRaw('v.user_id, v.orders, v.delivered_orders, v.spend, v.first_order_at, v.last_order_at,
            extract(epoch from (?::timestamptz - v.last_order_at)) / 86400 as days_quiet,
            case when v.orders > 1 then extract(epoch from (v.last_order_at - v.first_order_at)) / 86400 / (v.orders - 1) end as avg_gap_days', [$now]);
    }

    /**
     * The numbers on top of the page: how many customers, how many are repeat buyers, how many are new this month and how many have gone quiet (and
     * what those are worth together).
     *
     * @return array{customers: int, repeat: int, one_time: int, new_month: int, quiet: int, quiet_spend: string, spend: string}
     */
    public function summary(User $viewer, ?string $branchId = null): array
    {
        $now = CarbonImmutable::now();
        $cutoff = $now->subDays($this->quietDays());
        $monthStart = BusinessTime::now()->startOfMonth()->utc();
        $row = DB::query()->fromSub($this->base($this->scopeFor($viewer, $branchId), $now), 'v')->join('users as u', 'u.id', '=', 'v.user_id')->where('u.type', 'customer')
            ->selectRaw("count(*) as customers, count(*) filter (where v.orders >= 2) as repeat_customers, count(*) filter (where v.orders = 1) as one_time,
                count(*) filter (where v.first_order_at >= ?) as new_month,
                count(*) filter (where v.delivered_orders >= 1 and v.last_order_at <= ? and u.status = 'active') as quiet,
                coalesce(sum(v.spend) filter (where v.delivered_orders >= 1 and v.last_order_at <= ? and u.status = 'active'), 0)::numeric(14,2) as quiet_spend,
                coalesce(sum(v.spend), 0)::numeric(14,2) as spend", [$monthStart, $cutoff, $cutoff])->first();

        return ['customers' => (int) $row->customers, 'repeat' => (int) $row->repeat_customers, 'one_time' => (int) $row->one_time, 'new_month' => (int) $row->new_month,
            'quiet' => (int) $row->quiet, 'quiet_spend' => Money::of((string) $row->quiet_spend)->toDecimal(), 'spend' => Money::of((string) $row->spend)->toDecimal()];
    }

    /**
     * One filtered, sorted, paged list.
     *
     * @param  array{tab?: string, sort?: string, search?: string, status?: string, branch?: string}  $filters
     * @return LengthAwarePaginator<int, object>
     */
    public function paginate(User $viewer, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->query($viewer, $filters)->paginate(max(1, min($perPage, 100)))->through(fn (User $u) => $this->row($u));
    }

    /**
     * @param  array{tab?: string, sort?: string, search?: string, status?: string, branch?: string}  $filters
     * @return EloquentBuilder<User>
     */
    public function query(User $viewer, array $filters): EloquentBuilder
    {
        $now = CarbonImmutable::now();
        $tab = in_array($filters['tab'] ?? '', self::TABS, true) ? $filters['tab'] : 'top';
        $sort = in_array($filters['sort'] ?? '', self::SORTS, true) ? $filters['sort'] : ($tab === 'quiet' ? 'spend' : ($tab === 'new' ? 'last_order' : 'spend'));
        $cutoff = $now->subDays($this->quietDays());

        $q = User::query()->where('users.type', 'customer')->joinSub($this->base($this->scopeFor($viewer, ($filters['branch'] ?? '') !== '' ? $filters['branch'] : null), $now), 'v', 'v.user_id', '=', 'users.id')
            ->select('users.*')->selectRaw('v.orders as v_orders, v.delivered_orders as v_delivered, v.spend as v_spend, v.first_order_at as v_first, v.last_order_at as v_last,
                v.days_quiet as v_days_quiet, v.avg_gap_days as v_gap');
        app(CustomerFilter::class)->apply($q, array_filter(['search' => $filters['search'] ?? '', 'status' => $filters['status'] ?? '']));
        match ($tab) {
            'repeat' => $q->where('v.orders', '>=', 2),
            'one_time' => $q->where('v.orders', 1),
            'new' => $q->where('v.first_order_at', '>=', BusinessTime::now()->startOfMonth()->utc()),
            'quiet' => $q->where('v.delivered_orders', '>=', 1)->where('v.last_order_at', '<=', $cutoff)->where('users.status', 'active'),
            default => null,
        };
        match ($sort) {
            'orders' => $q->orderByDesc('v.orders'),
            'basket' => $q->orderByRaw('v.spend / nullif(v.orders, 0) desc nulls last'),
            'last_order' => $q->orderByDesc('v.last_order_at'),
            'quiet_days' => $q->orderByDesc('v.days_quiet'),
            'overdue' => $q->orderByRaw('case when v.orders > 1 and v.avg_gap_days > 0 then v.days_quiet / v.avg_gap_days end desc nulls last'),
            default => $q->orderByDesc('v.spend'),
        };

        return $q->orderBy('users.id');
    }

    /**
     * A row of the list in plain values: id, name, phone, email, orders, spend and basket (decimal strings), first_order_at, last_order_at, days_quiet (whole days),
     * usual_gap_days, late_by (how many usual gaps have passed) and delivered.
     */
    public function row(User $u): object
    {
        $orders = (int) $u->getAttribute('v_orders');
        $spend = Money::of((string) $u->getAttribute('v_spend'));
        $gap = $u->getAttribute('v_gap');
        $days = (float) $u->getAttribute('v_days_quiet');

        return (object) ['id' => (string) $u->id, 'name' => (string) $u->name, 'phone' => $u->phone, 'email' => $u->email, 'orders' => $orders, 'spend' => $spend->toDecimal(),
            'basket' => ($orders > 0 ? Money::ofMinor((int) round($spend->minor / $orders)) : Money::zero())->toDecimal(),
            'first_order_at' => CarbonImmutable::parse($u->getAttribute('v_first')), 'last_order_at' => CarbonImmutable::parse($u->getAttribute('v_last')),
            'days_quiet' => max(0, (int) floor($days)), 'usual_gap_days' => $gap !== null ? max(1, (int) round((float) $gap)) : null,
            'late_by' => $gap !== null && (float) $gap > 0 ? round($days / (float) $gap, 1) : null, 'delivered' => (int) $u->getAttribute('v_delivered') > 0];
    }

    /**
     * Exact, live figures for a few customers (their own page, one page of the customers list): the same rule as the rollup, read straight from the
     * orders of those customers only.
     *
     * @param  list<string>  $userIds
     * @return array<string, array{orders: int, spend: string, first_at: ?CarbonImmutable, last_at: ?CarbonImmutable}>
     */
    public function lifetime(BranchScope $scope, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $rows = $scope->apply(SalesBasis::orderShares(null, null, $userIds), 's.branch_id')
            ->selectRaw('s.user_id, count(distinct s.order_id) as orders, coalesce(sum(s.share), 0)::numeric(14,2) as spend, min(s.placed_at) as first_at, max(s.placed_at) as last_at')
            ->groupBy('s.user_id')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->user_id] = ['orders' => (int) $r->orders, 'spend' => Money::of((string) $r->spend)->toDecimal(),
                'first_at' => $r->first_at ? CarbonImmutable::parse($r->first_at) : null, 'last_at' => $r->last_at ? CarbonImmutable::parse($r->last_at) : null];
        }

        return $out;
    }

    /** One customer's live lifetime figures for the viewer, zeros when they have nothing in the viewer's branches. @return array{orders: int, spend: string, first_at: ?CarbonImmutable, last_at: ?CarbonImmutable} */
    public function lifetimeOf(User $viewer, string $userId): array
    {
        return $this->lifetime($this->scopeFor($viewer), [$userId])[$userId] ?? ['orders' => 0, 'spend' => '0.00', 'first_at' => null, 'last_at' => null];
    }
}
