<?php

namespace App\Domain\Branches\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchHour;
use Carbon\CarbonImmutable;

/**
 * Opening-status calculation in the branch's timezone (default Asia/Qatar).
 * Supports weekly hours, break periods, overnight hours (closes_at <= opens_at), holidays and
 * temporary closures, plus the manual "temporarily_closed" switch.
 */
class BranchHoursService
{
    /** @return array{is_open: bool, reason: string, closes_at: ?string, next_open_at: ?string} */
    public function status(Branch $branch, ?CarbonImmutable $at = null): array
    {
        $tz = $branch->timezone ?: config('platform.business_timezone');
        $at = ($at ?? CarbonImmutable::now())->setTimezone($tz);
        $branch->loadMissing(['hours', 'holidays']);

        if (! $branch->isActive()) {
            return ['is_open' => false, 'reason' => 'inactive', 'closes_at' => null, 'next_open_at' => null];
        }
        if ($branch->opening_status === 'temporarily_closed') {
            return ['is_open' => false, 'reason' => 'temporarily_closed', 'closes_at' => null, 'next_open_at' => null];
        }
        if ($holiday = $this->holidayAt($branch, $at)) {
            return ['is_open' => false, 'reason' => $holiday->type, 'closes_at' => null,
                'next_open_at' => $this->nextOpening($branch, $holiday->ends_at->setTimezone($tz))?->toIso8601String()];
        }
        if ($window = $this->windowContaining($branch, $at)) {
            return ['is_open' => true, 'reason' => 'open', 'closes_at' => $window[1]->toIso8601String(), 'next_open_at' => null];
        }

        return ['is_open' => false, 'reason' => 'outside_hours', 'closes_at' => null, 'next_open_at' => $this->nextOpening($branch, $at)?->toIso8601String()];
    }

    public function isOpen(Branch $branch, ?CarbonImmutable $at = null): bool
    {
        return $this->status($branch, $at)['is_open'];
    }

    /** First moment at/after $from when the branch is open, searching up to 14 days ahead. */
    public function nextOpening(Branch $branch, CarbonImmutable $from): ?CarbonImmutable
    {
        $cursor = $from;
        for ($day = 0; $day < 14; $day++) {
            $date = $from->startOfDay()->addDays($day);
            foreach ($this->windowsForDate($branch, $date) as [$start, $end]) {
                $candidate = $start->greaterThan($cursor) ? $start : ($end->greaterThan($cursor) ? $cursor : null);
                if ($candidate && ! $this->holidayAt($branch, $candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /** Open windows (start, end) that START on $date; overnight windows end the next day. */
    public function windowsForDate(Branch $branch, CarbonImmutable $date): array
    {
        /** @var BranchHour|null $hour */
        $hour = $branch->hours->firstWhere('day_of_week', $date->dayOfWeek);
        if (! $hour || $hour->is_closed || ! $hour->opens_at || ! $hour->closes_at) {
            return [];
        }
        $open = $date->setTimeFromTimeString($hour->opens_at);
        $close = $date->setTimeFromTimeString($hour->closes_at);
        if ($close->lessThanOrEqualTo($open)) {
            $close = $close->addDay(); // overnight
        }
        if ($hour->break_starts_at && $hour->break_ends_at) {
            $bs = $date->setTimeFromTimeString($hour->break_starts_at);
            $be = $date->setTimeFromTimeString($hour->break_ends_at);
            if ($bs->lessThan($open)) {
                $bs = $bs->addDay();
                $be = $be->addDay();
            }
            if ($bs->greaterThan($open) && $be->lessThan($close) && $be->greaterThan($bs)) {
                return [[$open, $bs], [$be, $close]];
            }
        }

        return [[$open, $close]];
    }

    private function windowContaining(Branch $branch, CarbonImmutable $at): ?array
    {
        foreach ([$at->subDay()->startOfDay(), $at->startOfDay()] as $date) {
            foreach ($this->windowsForDate($branch, $date) as $w) {
                if ($at->greaterThanOrEqualTo($w[0]) && $at->lessThan($w[1])) {
                    return $w;
                }
            }
        }

        return null;
    }

    private function holidayAt(Branch $branch, CarbonImmutable $at)
    {
        return $branch->holidays->first(fn ($h) => $at->greaterThanOrEqualTo($h->starts_at) && $at->lessThan($h->ends_at));
    }
}
