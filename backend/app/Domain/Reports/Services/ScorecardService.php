<?php

namespace App\Domain\Reports\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Operations\Services\OpsClock;
use App\Support\BusinessTime;
use App\Support\DomainException;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The branch and driver scorecard: how the work went over a period, set beside the period before it, in plain numbers the owner can ask about.
 *
 * WHO SEES WHAT. Whoever holds `reports.view` sees the branches that right covers (the owner: all; a branch manager: their own), and, on the Drivers tab, only
 * the work and the cash of drivers AT those branches. A branch filter can only narrow what the right covers. Drivers and ordinary staff do not hold the right
 * and see nothing (the route answers 403). Exporting needs `reports.export` at the same branches. The branches are worked out here, on the server, from the
 * viewer's roles; nothing the browser sends can widen them.
 *
 * HOW FAIRLY. Every figure carries the number of cases it is built from. Below the minimum sample ({@see ScorecardSettings}) a figure is not shown as a
 * percentage or a time at all; speeds are a MEDIAN and a 90th percentile (the middle order and the slow end), never only an average a single odd order can
 * move; a failure the customer caused (not home, wrong address, refused) is counted apart from one the team caused ({@see ScorecardCauses}); a change
 * against the previous period is only given when both periods had enough cases; and the page has no ranking.
 *
 * HOW FAST. Each family of figures is one grouped query, over the period (twice: this and the one before) and the viewer's branches, and the answer is
 * kept for {@see self::CACHE_SECONDS} seconds under a key made of the period, the branches and the minimum sample. Everything kept is plain arrays with no
 * names of customers: the key is about WHAT is asked, never WHO asks.
 *
 * WHERE THE DEFINITIONS ARE: {@see ScorecardBranchNumbers} (which orders count and how each figure is measured, pointing at the daily close and the
 * owner pack it follows) and the guide docs/guides/BRANCH_AND_DRIVER_SCORECARD.md.
 */
final class ScorecardService
{
    public const CACHE_SECONDS = 120;

    public const TABS = ['branches', 'drivers'];

    public function __construct(
        private readonly AccessControl $access,
        private readonly ScorecardBranchNumbers $branchNumbers,
        private readonly ScorecardDriverNumbers $driverNumbers,
        private readonly ScorecardSettings $settings,
        private readonly OpsClock $clock,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The active branches the viewer may see reports for (a branch filter can only narrow them), in menu order. With $forExport also limited to the
     * branches the viewer may export reports of.
     *
     * @return EloquentCollection<int, Branch>
     */
    public function branchesFor(User $viewer, ?string $branchId = null, bool $forExport = false): EloquentCollection
    {
        $query = Branch::query()->active();
        $this->access->scope($viewer, 'reports.view')->narrow($branchId)->apply($query, 'id');
        if ($forExport) {
            $this->access->scope($viewer, 'reports.export')->apply($query, 'id');
        }

        return $query->orderBy('sort_order')->orderBy('code')->get(['id', 'code', 'name_en', 'name_ar']);
    }

    /**
     * The Branches tab: one entry per branch the viewer may see, and the line for all of them together (null when it is only one branch).
     *
     * @return array{period: array<string, mixed>, min_sample: int, generated_at: string, rows: list<array<string, mixed>>, total: ?array<string, mixed>}
     */
    public function branches(User $viewer, ScorecardPeriod $period, ?string $branchId = null, bool $forExport = false): array
    {
        $branches = $this->branchesFor($viewer, $branchId, $forExport);
        $ids = $branches->modelKeys();
        $min = $this->settings->minSample();
        $lateBefore = $this->lateCashBefore();

        $cached = Cache::remember($this->key('branches', $period, $ids, $min), self::CACHE_SECONDS, function () use ($branches, $ids, $period, $min, $lateBefore): array {
            $now = $this->branchNumbers->forPeriod($ids, $period);
            $before = $this->branchNumbers->forPeriod($ids, $period->previous());
            $standing = $this->branchNumbers->standing($ids, $lateBefore);
            $rows = [];
            foreach ($branches as $b) {
                $rows[] = ['id' => (string) $b->id, 'code' => (string) $b->code, 'name_en' => (string) $b->name_en, 'name_ar' => (string) ($b->name_ar ?: $b->name_en)]
                    + $this->shapeBranch($now[$b->id], $before[$b->id], $standing[$b->id], $min);
            }
            $total = null;
            if (count($ids) > 1) {
                $total = $this->shapeBranch($this->mergeBranches($now), $this->mergeBranches($before), $this->mergeStanding($standing), $min);
            }

            return ['rows' => $rows, 'total' => $total, 'generated_at' => BusinessTime::iso(now()) ?? ''];
        });

        return ['period' => $this->describe($period), 'min_sample' => $min, 'generated_at' => $cached['generated_at'], 'rows' => $cached['rows'], 'total' => $cached['total']];
    }

    /**
     * The Drivers tab: one entry per driver at the viewer's branches, alphabetical (never ranked), and the line for all of them together.
     *
     * @return array{period: array<string, mixed>, min_sample: int, generated_at: string, rows: list<array<string, mixed>>, total: array<string, mixed>}
     */
    public function drivers(User $viewer, ScorecardPeriod $period, ?string $branchId = null, bool $forExport = false): array
    {
        $branches = $this->branchesFor($viewer, $branchId, $forExport);
        $ids = $branches->modelKeys();
        $min = $this->settings->minSample();
        $lateBefore = $this->lateCashBefore();

        $cached = Cache::remember($this->key('drivers', $period, $ids, $min), self::CACHE_SECONDS, function () use ($ids, $period, $min, $lateBefore): array {
            $now = $this->driverNumbers->forPeriod($ids, $period);
            $before = $this->driverNumbers->forPeriod($ids, $period->previous());
            $driverIds = array_keys($now);
            $names = $driverIds === [] ? collect() : User::query()->whereIn('id', $driverIds)->get(['id', 'name'])->keyBy('id');
            $holding = $this->driverNumbers->standing($ids, $driverIds);
            $rows = [];
            foreach ($driverIds as $id) {
                $rows[] = ['id' => (string) $id, 'name' => (string) ($names->get($id)?->name ?? '—')]
                    + $this->shapeDriver($now[$id], $before[$id] ?? ScorecardDriverNumbers::blank(), $holding[$id] ?? null, $min, $lateBefore);
            }
            usort($rows, fn (array $a, array $b) => [mb_strtolower($a['name']), $a['id']] <=> [mb_strtolower($b['name']), $b['id']]);
            $everyone = $this->mergeDrivers($now);
            $total = $this->shapeDriver($everyone, $this->mergeDrivers($before), $this->mergeHolding($holding), $min, $lateBefore);

            return ['rows' => $rows, 'total' => $total, 'generated_at' => BusinessTime::iso(now()) ?? ''];
        });

        return ['period' => $this->describe($period), 'min_sample' => $min, 'generated_at' => $cached['generated_at'], 'rows' => $cached['rows'], 'total' => $cached['total']];
    }

    /**
     * One driver's numbers with the orders behind them. Null when the viewer may not see this driver (not a driver at any of the viewer's branches, nor
     * given any delivery there in the period): the same answer for a driver that does not exist, so the answer reveals nothing.
     *
     * @return array{driver: array<string, mixed>, ledger: list<array<string, mixed>>, truncated: bool, period: array<string, mixed>, min_sample: int}|null
     */
    public function driver(User $viewer, string $driverId, ScorecardPeriod $period, ?string $branchId = null): ?array
    {
        $ids = $this->branchesFor($viewer, $branchId)->modelKeys();
        if (! Str::isUuid($driverId) || $ids === []) {
            return null;
        }
        $now = $this->driverNumbers->forPeriod($ids, $period, $driverId);
        if (! isset($now[$driverId])) {
            return null;
        }
        $min = $this->settings->minSample();
        $before = $this->driverNumbers->forPeriod($ids, $period->previous(), $driverId)[$driverId] ?? ScorecardDriverNumbers::blank();
        $holding = $this->driverNumbers->standing($ids, [$driverId])[$driverId] ?? null;
        $name = (string) (User::query()->whereKey($driverId)->value('name') ?? '—');
        $ledger = $this->driverNumbers->ledger($ids, $driverId, $period);

        return [
            'driver' => ['id' => $driverId, 'name' => $name] + $this->shapeDriver($now[$driverId], $before, $holding, $min, $this->lateCashBefore()),
            'ledger' => $ledger['rows'], 'truncated' => $ledger['truncated'], 'period' => $this->describe($period), 'min_sample' => $min,
        ];
    }

    /** Whether the viewer may open this driver (a driver at one of the viewer's branches, or one given a delivery there in the period): the cheap check, without the orders. */
    public function mayOpenDriver(User $viewer, string $driverId, ScorecardPeriod $period, ?string $branchId = null): bool
    {
        $ids = $this->branchesFor($viewer, $branchId)->modelKeys();

        return Str::isUuid($driverId) && $ids !== [] && isset($this->driverNumbers->forPeriod($ids, $period, $driverId)[$driverId]);
    }

    /**
     * The rows of a CSV of one tab, for people who may export reports at the branches the export covers. Every export is written to the audit log.
     *
     * @return array{tab: string, period: array<string, mixed>, data: array{rows: list<array<string, mixed>>}}
     *
     * @throws DomainException
     */
    public function export(User $viewer, string $tab, ScorecardPeriod $period, ?string $branchId = null): array
    {
        if (! $this->access->allows($viewer, 'reports.view') || ! $this->access->allows($viewer, 'reports.export')) {
            throw DomainException::make('forbidden', 403);
        }
        $tab = in_array($tab, self::TABS, true) ? $tab : 'branches';
        $data = $tab === 'drivers' ? $this->drivers($viewer, $period, $branchId, forExport: true) : $this->branches($viewer, $period, $branchId, forExport: true);
        $this->audit->log('scorecard.exported', 'Scorecard', [], ['tab' => $tab, 'from' => $period->from, 'to' => $period->to, 'branch' => $branchId ?: null, 'rows' => count($data['rows'])]);

        return ['tab' => $tab, 'period' => $data['period'], 'data' => $data];
    }

    /** Forgets what is kept for this window and these branches (the page's "refresh" button). */
    public function forget(User $viewer, ScorecardPeriod $period, ?string $branchId = null): void
    {
        $ids = $this->branchesFor($viewer, $branchId)->modelKeys();
        $min = $this->settings->minSample();
        foreach (self::TABS as $tab) {
            Cache::forget($this->key($tab, $period, $ids, $min));
        }
    }

    // ── Shaping ──────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $now  raw cases of this period ({@see ScorecardBranchNumbers::blank()})
     * @param  array<string, mixed>  $before  raw cases of the period before
     * @param  array<string, mixed>  $standing
     * @return array<string, mixed>
     */
    private function shapeBranch(array $now, array $before, array $standing, int $min): array
    {
        $m = fn (?float $value, int $n, ?float $previous, int $previousN) => ScorecardStats::metric($value, $n, $previous, $previousN, $min);
        $speed = fn (string $key) => [
            'median' => $m(ScorecardStats::round1(ScorecardStats::median($now[$key])), count($now[$key]), ScorecardStats::round1(ScorecardStats::median($before[$key])), count($before[$key])),
            'p90' => $m(ScorecardStats::round1(ScorecardStats::p90($now[$key])), count($now[$key]), ScorecardStats::round1(ScorecardStats::p90($before[$key])), count($before[$key])),
            'n' => count($now[$key]),
        ];
        $ran = $now['delivered'] + $now['failed'];
        $ranBefore = $before['delivered'] + $before['failed'];
        $reached = $now['delivered'] + $now['collected'];
        $reachedBefore = $before['delivered'] + $before['collected'];
        $pickups = $now['collected'] + $now['pass_expired'];
        $pickupsBefore = $before['collected'] + $before['pass_expired'];
        $failNow = ScorecardCauses::split($now['fail_reasons']);
        $failBefore = ScorecardCauses::split($before['fail_reasons']);
        $oldest = $standing['cash_oldest'] !== null ? max(0, intdiv(CarbonImmutable::now()->getTimestamp() - CarbonImmutable::parse($standing['cash_oldest'])->getTimestamp(), 60)) : null;

        return [
            'orders' => ScorecardStats::count($now['placed'], $before['placed']),
            'accept' => $speed('accept') + ['capped' => $now['timing_capped']],
            'prepare' => $speed('prepare') + ['capped' => $now['timing_capped']],
            'delivery' => [
                'on_time' => $m(ScorecardStats::rate($now['on_time'], $now['promised']), $now['promised'], ScorecardStats::rate($before['on_time'], $before['promised']), $before['promised']),
                'delivered' => $now['delivered'], 'promised' => $now['promised'], 'on_time_count' => $now['on_time'], 'no_promise' => $now['delivered'] - $now['promised'],
                'moved' => $now['moved'], 'previous_delivered' => $before['delivered'],
            ],
            'pickup' => [
                'in_time' => $m(ScorecardStats::rate($now['collected_in_time'], $pickups), $pickups, ScorecardStats::rate($before['collected_in_time'], $pickupsBefore), $pickupsBefore),
                'collected' => $now['collected'], 'in_time_count' => $now['collected_in_time'], 'expired' => $now['pass_expired'], 'ttl_hours' => max(1, (int) config('platform.pickup.token_ttl_hours', 72)),
            ],
            'cancel' => [
                'rate' => $m(ScorecardStats::rate($now['cancelled'], $now['placed']), $now['placed'], ScorecardStats::rate($before['cancelled'], $before['placed']), $before['placed']),
                'count' => $now['cancelled'], 'previous_count' => $before['cancelled'], 'reasons' => $this->sorted($now['cancel_reasons']),
            ],
            'failed' => [
                'rate' => $m(ScorecardStats::rate($now['failed'], $ran), $ran, ScorecardStats::rate($before['failed'], $ranBefore), $ranBefore),
                'team_rate' => $m(ScorecardStats::rate($failNow['team'], $ran), $ran, ScorecardStats::rate($failBefore['team'], $ranBefore), $ranBefore),
                'count' => $now['failed'], 'previous_count' => $before['failed'], 'reasons' => $this->sorted($failNow['reasons']),
                'customer' => $failNow['customer'], 'team' => $failNow['team'], 'other' => $failNow['other'], 'previous_team' => $failBefore['team'], 'previous_customer' => $failBefore['customer'],
            ],
            'claims' => [
                'rate' => $m(ScorecardStats::rate($now['claims'], $reached), $reached, ScorecardStats::rate($before['claims'], $reachedBefore), $reachedBefore),
                'count' => $now['claims'], 'previous_count' => $before['claims'], 'reasons' => $this->sorted($now['claim_reasons']),
            ],
            'substitutions' => [
                'rate' => $m(ScorecardStats::rate($now['substitutions'], $now['placed']), $now['placed'], ScorecardStats::rate($before['substitutions'], $before['placed']), $before['placed']),
                'count' => $now['substitutions'], 'previous_count' => $before['substitutions'],
            ],
            'cash' => [
                'net' => $now['cash_net'], 'previous_net' => $before['cash_net'], 'net_change' => Money::of($now['cash_net'])->minus(Money::of($before['cash_net']))->toDecimal(),
                'abs' => $now['cash_abs'], 'previous_abs' => $before['cash_abs'], 'variances' => $now['variances'], 'previous_variances' => $before['variances'],
                'handins' => $now['handins'], 'previous_handins' => $before['handins'], 'open_variances' => $standing['open_variances'],
                'held' => $standing['cash_held'], 'held_amount' => $standing['cash_held_amount'], 'late' => $standing['cash_late'], 'late_amount' => $standing['cash_late_amount'], 'oldest_minutes' => $oldest,
                'limit_hours' => intdiv($this->clock->limits(OpsClock::CASH)['amber'], 60),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $now
     * @param  array<string, mixed>  $before
     * @param  array{held: int, amount: string, oldest: ?string}|null  $holding
     * @return array<string, mixed>
     */
    private function shapeDriver(array $now, array $before, ?array $holding, int $min, CarbonImmutable $lateBefore): array
    {
        $m = fn (?float $value, int $n, ?float $previous, int $previousN) => ScorecardStats::metric($value, $n, $previous, $previousN, $min);
        $ran = $now['delivered'] + $now['failed'];
        $ranBefore = $before['delivered'] + $before['failed'];
        // Jobs that reached the driver in the window: the ones he finished one way or the other, plus the ones he handed back or did not answer.
        $offered = $ran + $now['declined'] + $now['timeouts'];
        $offeredBefore = $ranBefore + $before['declined'] + $before['timeouts'];
        $failNow = ScorecardCauses::split($now['fail_reasons']);
        $failBefore = ScorecardCauses::split($before['fail_reasons']);
        $oldest = $holding['oldest'] ?? null;
        $oldestAt = $oldest !== null ? CarbonImmutable::parse($oldest) : null;

        return [
            'delivered' => ScorecardStats::count($now['delivered'], $before['delivered']),
            'on_time' => $m(ScorecardStats::rate($now['on_time'], $now['promised']), $now['promised'], ScorecardStats::rate($before['on_time'], $before['promised']), $before['promised']) + ['promised' => $now['promised']],
            'trip' => [
                'median' => $m(ScorecardStats::round1($now['trip_median']), $now['trips'], ScorecardStats::round1($before['trip_median']), $before['trips']),
                'p90' => $m(ScorecardStats::round1($now['trip_p90']), $now['trips'], ScorecardStats::round1($before['trip_p90']), $before['trips']),
                'average' => $m(ScorecardStats::round1($now['trip_avg']), $now['trips'], ScorecardStats::round1($before['trip_avg']), $before['trips']),
                'n' => $now['trips'],
            ],
            'failed' => [
                'rate' => $m(ScorecardStats::rate($now['failed'], $ran), $ran, ScorecardStats::rate($before['failed'], $ranBefore), $ranBefore),
                'team_rate' => $m(ScorecardStats::rate($failNow['team'], $ran), $ran, ScorecardStats::rate($failBefore['team'], $ranBefore), $ranBefore),
                'count' => $now['failed'], 'previous_count' => $before['failed'], 'reasons' => $this->sorted($failNow['reasons']),
                'customer' => $failNow['customer'], 'team' => $failNow['team'], 'other' => $failNow['other'],
            ],
            'jobs' => [
                'declined' => $now['declined'], 'timeouts' => $now['timeouts'], 'off_duty' => $now['off_duty'],
                'previous_declined' => $before['declined'], 'previous_timeouts' => $before['timeouts'], 'offered' => $offered,
                'rate' => $m(ScorecardStats::rate($now['declined'] + $now['timeouts'], $offered), $offered, ScorecardStats::rate($before['declined'] + $before['timeouts'], $offeredBefore), $offeredBefore),
            ],
            'cash' => [
                'held' => $holding['held'] ?? 0, 'amount' => $holding['amount'] ?? '0.00', 'oldest_minutes' => $oldestAt !== null ? max(0, intdiv(CarbonImmutable::now()->getTimestamp() - $oldestAt->getTimestamp(), 60)) : null,
                'over_limit' => $oldestAt !== null && $oldestAt <= $lateBefore, 'limit_hours' => intdiv($this->clock->limits(OpsClock::CASH)['amber'], 60),
                'net' => $now['cash_net'], 'previous_net' => $before['cash_net'], 'abs' => $now['cash_abs'], 'variances' => $now['variances'], 'previous_variances' => $before['variances'],
                'handins' => $now['handins'], 'previous_handins' => $before['handins'],
            ],
        ];
    }

    /** @param  array<string, int>  $byReason @return array<string, int> biggest first, ties by code */
    private function sorted(array $byReason): array
    {
        $keys = array_keys($byReason);
        usort($keys, fn (string $a, string $b) => [$byReason[$b], $a] <=> [$byReason[$a], $b]);

        return array_combine($keys, array_map(fn (string $k) => $byReason[$k], $keys)) ?: [];
    }

    // ── All branches / all drivers together: the sums of the cases, so a rate is worked out from the sums, never averaged ─────────

    /**
     * @param  array<string, array<string, mixed>>  $raws
     * @return array<string, mixed>
     */
    private function mergeBranches(array $raws): array
    {
        $sum = ScorecardBranchNumbers::blank();
        foreach ($raws as $raw) {
            foreach (['placed', 'delivered', 'promised', 'on_time', 'moved', 'collected', 'collected_in_time', 'pass_expired', 'cancelled', 'failed', 'claims', 'substitutions', 'handins', 'variances'] as $k) {
                $sum[$k] += $raw[$k];
            }
            foreach (['accept', 'prepare'] as $k) {
                $sum[$k] = array_merge($sum[$k], $raw[$k]);
            }
            foreach (['cancel_reasons', 'fail_reasons', 'claim_reasons'] as $k) {
                foreach ($raw[$k] as $code => $n) {
                    $sum[$k][$code] = ($sum[$k][$code] ?? 0) + $n;
                }
            }
            foreach (['cash_net', 'cash_abs'] as $k) {
                $sum[$k] = Money::of($sum[$k])->plus(Money::of($raw[$k]))->toDecimal();
            }
            $sum['timing_capped'] = $sum['timing_capped'] || $raw['timing_capped'];
        }

        return $sum;
    }

    /**
     * @param  array<string, array<string, mixed>>  $standing
     * @return array<string, mixed>
     */
    private function mergeStanding(array $standing): array
    {
        $sum = ['open_variances' => 0, 'cash_held' => 0, 'cash_held_amount' => '0.00', 'cash_late' => 0, 'cash_late_amount' => '0.00', 'cash_oldest' => null];
        foreach ($standing as $s) {
            $sum['open_variances'] += $s['open_variances'];
            $sum['cash_held'] += $s['cash_held'];
            $sum['cash_late'] += $s['cash_late'];
            $sum['cash_held_amount'] = Money::of($sum['cash_held_amount'])->plus(Money::of($s['cash_held_amount']))->toDecimal();
            $sum['cash_late_amount'] = Money::of($sum['cash_late_amount'])->plus(Money::of($s['cash_late_amount']))->toDecimal();
            if ($s['cash_oldest'] !== null && ($sum['cash_oldest'] === null || CarbonImmutable::parse($s['cash_oldest']) < CarbonImmutable::parse($sum['cash_oldest']))) {
                $sum['cash_oldest'] = $s['cash_oldest'];
            }
        }

        return $sum;
    }

    /**
     * All the drivers together. The trip time is a percentile the database worked out per driver, which cannot be merged, so the line of everyone leaves
     * it out (null, no readings).
     *
     * @param  array<string, array<string, mixed>>  $raws
     * @return array<string, mixed>
     */
    private function mergeDrivers(array $raws): array
    {
        $sum = ScorecardDriverNumbers::blank();
        foreach ($raws as $raw) {
            foreach (['delivered', 'promised', 'on_time', 'failed', 'declined', 'timeouts', 'off_duty', 'handins', 'variances'] as $k) {
                $sum[$k] += $raw[$k];
            }
            foreach ($raw['fail_reasons'] as $code => $n) {
                $sum['fail_reasons'][$code] = ($sum['fail_reasons'][$code] ?? 0) + $n;
            }
            foreach (['cash_net', 'cash_abs'] as $k) {
                $sum[$k] = Money::of($sum[$k])->plus(Money::of($raw[$k]))->toDecimal();
            }
        }

        return $sum;
    }

    /**
     * @param  array<string, array{held: int, amount: string, oldest: ?string}>  $holding
     * @return array{held: int, amount: string, oldest: ?string}
     */
    private function mergeHolding(array $holding): array
    {
        $sum = ['held' => 0, 'amount' => '0.00', 'oldest' => null];
        foreach ($holding as $h) {
            $sum['held'] += $h['held'];
            $sum['amount'] = Money::of($sum['amount'])->plus(Money::of($h['amount']))->toDecimal();
            if ($h['oldest'] !== null && ($sum['oldest'] === null || CarbonImmutable::parse($h['oldest']) < CarbonImmutable::parse($sum['oldest']))) {
                $sum['oldest'] = $h['oldest'];
            }
        }

        return $sum;
    }

    // ── Small things ─────────────────────────────────────────────────────────────────────────────────────────────────

    /** Cash collected at or before this moment, and still not handed in, is past the order clock's cash limit. */
    private function lateCashBefore(): CarbonImmutable
    {
        return CarbonImmutable::now()->subMinutes($this->clock->limits(OpsClock::CASH)['amber'])->utc();
    }

    /**
     * What is asked, never who asks: the tab, the window, the branches, the minimum sample and the cash limit (the limit as minutes, so the key holds
     * still for the whole time the answer is kept).
     *
     * @param  list<string>  $ids
     */
    private function key(string $tab, ScorecardPeriod $period, array $ids, int $min): string
    {
        sort($ids);

        return 'scorecard:v1:'.$tab.':'.$period->key().':'.md5(implode(',', $ids)).':min'.$min.':cash'.$this->clock->limits(OpsClock::CASH)['amber'];
    }

    /** @return array{from: string, to: string, days: int, previous_from: string, previous_to: string, preset: ?int, includes_today: bool} */
    private function describe(ScorecardPeriod $period): array
    {
        $before = $period->previous();

        return ['from' => $period->from, 'to' => $period->to, 'days' => $period->days(), 'previous_from' => $before->from, 'previous_to' => $before->to,
            'preset' => $period->preset(), 'includes_today' => $period->includesToday()];
    }
}
