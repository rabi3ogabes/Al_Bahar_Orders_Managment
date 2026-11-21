<?php

namespace App\Domain\Orders\Services;

use App\Domain\Operations\Services\BranchSchedules;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * When a question to the customer runs out: N "business minutes" after it was asked, counting only the minutes the branch is open (the same clock the
 * order clock uses, {@see BranchSchedules}), so a question asked at 21:50 does not expire at 22:20 while the shop is shut. A branch with no hours counts
 * wall-clock minutes. The moment is worked out once, when the question is asked, and stored, so the customer is shown a fixed time.
 */
final class SubstitutionClock
{
    /** The longest wall-clock wait one question may be given (the schedule is only walked this far). */
    private const HORIZON_MINUTES = 14 * 1440;

    public function __construct(private readonly BranchSchedules $schedules) {}

    public function deadline(?string $branchId, CarbonInterface $from, int $minutes): CarbonImmutable
    {
        $this->schedules->preload([$branchId]);
        $from = CarbonImmutable::instance($from);
        $minutes = max(1, $minutes);
        $hi = $minutes + self::HORIZON_MINUTES;
        if ($this->counted($branchId, $from, $from->addMinutes($hi)) < $minutes) {
            return $from->addMinutes($hi);
        }
        // The smallest wall-clock offset at which the branch has been open for $minutes.
        $lo = $minutes;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($this->counted($branchId, $from, $from->addMinutes($mid)) >= $minutes) {
                $hi = $mid;
            } else {
                $lo = $mid + 1;
            }
        }

        return $from->addMinutes($lo);
    }

    /** Opening-hours minutes between two moments. */
    public function counted(?string $branchId, CarbonInterface $from, CarbonInterface $to): int
    {
        $this->schedules->preload([$branchId]);

        return $this->schedules->minutes($branchId, $from, $to)['counted'];
    }
}
