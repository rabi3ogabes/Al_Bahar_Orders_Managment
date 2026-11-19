<?php

namespace App\Domain\Operations\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchHour;
use App\Domain\Branches\Services\BranchHoursService;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Container\Attributes\Scoped;

/**
 * "Business minutes": the minutes a branch was OPEN between two moments, so an order placed at 23:00 is not late at 08:00 just because the
 * shop was shut all night.
 *
 *  - The weekly hours of the branch (`branch_hours`, with breaks and overnight hours) and its holidays and temporary closures are the rule.
 *  - A branch with no usable hours (none saved, or every day marked closed) falls back to plain wall-clock minutes: a missing schedule must
 *    never make the dashboard go quiet.
 *  - Only the last {@see self::HORIZON_DAYS} days are walked; older waits are measured from the start of that window (an order that old is
 *    late whatever the hours were, and the walk stays cheap).
 *  - Branch hours are loaded once per request for all the branches asked about (two queries), not once per row.
 */
#[Scoped]
final class BranchSchedules
{
    public const HORIZON_DAYS = 14;

    /** @var array<string, Branch> */
    private array $branches = [];

    /** @var array<string, true> branch ids already asked the database about (a branch that does not exist is remembered too) */
    private array $loaded = [];

    public function __construct(private readonly BranchHoursService $hours) {}

    /** Loads the schedules of these branches in one go (call it with every branch a screen is about to measure). @param  list<string|null>  $branchIds */
    public function preload(array $branchIds): void
    {
        $missing = array_values(array_diff(array_unique(array_filter($branchIds, is_string(...))), array_keys($this->loaded)));
        if ($missing === []) {
            return;
        }
        $horizon = CarbonImmutable::now()->subDays(self::HORIZON_DAYS + 2);
        $found = Branch::query()->whereIn('id', $missing)
            ->with(['hours', 'holidays' => fn ($q) => $q->where('ends_at', '>=', $horizon)])
            ->get(['id', 'timezone', 'status', 'opening_status']);
        foreach ($found as $branch) {
            $this->branches[$branch->id] = $branch;
        }
        foreach ($missing as $id) {
            $this->loaded[$id] = true;
        }
    }

    /** Does this branch have opening hours the clock can follow? (false: wall-clock fallback) */
    public function hasSchedule(?string $branchId): bool
    {
        $branch = $this->branch($branchId);

        return $branch !== null && $branch->hours->contains(fn (BranchHour $h) => ! $h->is_closed && $h->opens_at !== null && $h->closes_at !== null);
    }

    /** Is the branch open at this moment? A branch without a usable schedule counts as always open (nothing is paused, nothing is muted). */
    public function isOpen(?string $branchId, CarbonInterface $at): bool
    {
        $branch = $this->branch($branchId);
        if ($branch === null || ! $this->hasSchedule($branchId)) {
            return true;
        }
        $moment = CarbonImmutable::instance($at)->setTimezone($this->zone($branch));
        foreach ($this->holidayWindows($branch) as [$from, $to]) {
            if ($moment >= $from && $moment < $to) {
                return false;
            }
        }
        foreach ([$moment->subDay()->startOfDay(), $moment->startOfDay()] as $date) {
            foreach ($this->hours->windowsForDate($branch, $date) as [$start, $end]) {
                if ($moment >= $start && $moment < $end) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The minutes between two moments that count for the clock.
     *
     * @return array{counted: int, wall: int, paused: bool} `paused` is true when the branch has a schedule and is closed at $to
     */
    public function minutes(?string $branchId, CarbonInterface $from, CarbonInterface $to): array
    {
        $from = CarbonImmutable::instance($from);
        $to = CarbonImmutable::instance($to);
        $wall = $to > $from ? intdiv($to->getTimestamp() - $from->getTimestamp(), 60) : 0;
        $branch = $this->branch($branchId);
        if ($wall === 0 || $branch === null || ! $this->hasSchedule($branchId)) {
            return ['counted' => $wall, 'wall' => $wall, 'paused' => false];
        }

        $zone = $this->zone($branch);
        $start = $from->setTimezone($zone);
        $end = $to->setTimezone($zone);
        $earliest = $end->subDays(self::HORIZON_DAYS);
        if ($start < $earliest) {
            $start = $earliest;
        }
        $holidays = $this->holidayWindows($branch);
        $seconds = 0;
        for ($date = $start->startOfDay()->subDay(); $date <= $end->startOfDay(); $date = $date->addDay()) {
            foreach ($this->hours->windowsForDate($branch, $date) as [$open, $close]) {
                $a = $open > $start ? $open : $start;
                $z = $close < $end ? $close : $end;
                if ($z > $a) {
                    $seconds += $this->openSeconds($a, $z, $holidays);
                }
            }
        }

        return ['counted' => intdiv($seconds, 60), 'wall' => $wall, 'paused' => ! $this->isOpen($branchId, $to)];
    }

    /** The seconds of [a, z] that no holiday covers. @param list<array{0: CarbonImmutable, 1: CarbonImmutable}> $holidays sorted and merged */
    private function openSeconds(CarbonImmutable $a, CarbonImmutable $z, array $holidays): int
    {
        $total = $z->getTimestamp() - $a->getTimestamp();
        foreach ($holidays as [$from, $to]) {
            $overlapStart = max($a->getTimestamp(), $from->getTimestamp());
            $overlapEnd = min($z->getTimestamp(), $to->getTimestamp());
            if ($overlapEnd > $overlapStart) {
                $total -= $overlapEnd - $overlapStart;
            }
        }

        return max(0, $total);
    }

    /** @return list<array{0: CarbonImmutable, 1: CarbonImmutable}> the closures of the branch, merged so overlapping ones are not subtracted twice */
    private function holidayWindows(Branch $branch): array
    {
        $zone = $this->zone($branch);
        $list = [];
        foreach ($branch->holidays as $holiday) {
            $list[] = [CarbonImmutable::instance($holiday->starts_at)->setTimezone($zone), CarbonImmutable::instance($holiday->ends_at)->setTimezone($zone)];
        }
        usort($list, fn (array $x, array $y) => $x[0] <=> $y[0]);
        $merged = [];
        foreach ($list as [$from, $to]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $from <= $merged[$last][1]) {
                if ($to > $merged[$last][1]) {
                    $merged[$last][1] = $to;
                }

                continue;
            }
            $merged[] = [$from, $to];
        }

        return $merged;
    }

    private function branch(?string $branchId): ?Branch
    {
        if ($branchId === null) {
            return null;
        }
        if (! isset($this->loaded[$branchId])) {
            $this->preload([$branchId]);
        }

        return $this->branches[$branchId] ?? null;
    }

    private function zone(Branch $branch): string
    {
        $zone = (string) ($branch->timezone ?: '');

        return $zone !== '' ? $zone : BusinessTime::zone();
    }
}
