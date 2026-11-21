<?php

namespace App\Domain\Reports\Services;

use App\Support\BusinessTime;
use App\Support\DomainException;
use Carbon\CarbonImmutable;

/**
 * The window a scorecard looks at, in whole Qatar business days (both ends included), and the window of the same length just before it, which every
 * number is compared with. A preset ("the last 30 days") is made of COMPLETE days and ends yesterday, so the comparison is never a finished month
 * against half a day; a custom range may end today (the page says today is counted so far).
 */
final class ScorecardPeriod
{
    /** The longest range a person may ask for: a year (and a leap day). */
    public const MAX_DAYS = 366;

    /** The ready-made choices of the page. */
    public const PRESETS = [7, 30, 90];

    private function __construct(public readonly string $from, public readonly string $to) {}

    /** The last $days complete days: the $days days that ended yesterday. */
    public static function last(int $days, ?CarbonImmutable $now = null): self
    {
        $days = max(1, min($days, self::MAX_DAYS));
        $today = ($now ?? BusinessTime::now())->setTimezone(BusinessTime::zone())->startOfDay();

        return new self($today->subDays($days)->toDateString(), $today->subDay()->toDateString());
    }

    /**
     * A range typed by a person. Both ends must be real calendar days, the end may not be in the future and the range may not be longer than
     * {@see self::MAX_DAYS} days.
     *
     * @throws DomainException
     */
    public static function between(string $from, string $to, ?CarbonImmutable $now = null): self
    {
        if (! self::isDate($from) || ! self::isDate($to)) {
            throw new DomainException('scorecard_bad_period', __('scorecard.errors.bad_dates'));
        }
        $today = ($now ?? BusinessTime::now())->setTimezone(BusinessTime::zone())->toDateString();
        if ($from > $to) {
            throw new DomainException('scorecard_bad_period', __('scorecard.errors.from_after_to'));
        }
        if ($to > $today) {
            throw new DomainException('scorecard_bad_period', __('scorecard.errors.in_future'));
        }
        $period = new self($from, $to);
        if ($period->days() > self::MAX_DAYS) {
            throw new DomainException('scorecard_bad_period', __('scorecard.errors.too_long', ['days' => self::MAX_DAYS]));
        }

        return $period;
    }

    public static function isDate(string $date): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $date));

        return checkdate($m, $d, $y);
    }

    /** Days in the range, both ends counted. */
    public function days(): int
    {
        return (int) CarbonImmutable::parse($this->from)->diffInDays(CarbonImmutable::parse($this->to)) + 1;
    }

    /** The window of the same length that ended the day before this one began. */
    public function previous(): self
    {
        $days = $this->days();
        $from = CarbonImmutable::parse($this->from);

        return new self($from->subDays($days)->toDateString(), $from->subDay()->toDateString());
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} the first and last instant of the range in UTC, as the tables store time */
    public function bounds(): array
    {
        return BusinessTime::dateRange($this->from, $this->to);
    }

    /** True when the range includes today (so its last day is not finished). */
    public function includesToday(?CarbonImmutable $now = null): bool
    {
        return $this->to >= ($now ?? BusinessTime::now())->setTimezone(BusinessTime::zone())->toDateString();
    }

    /** The preset (7, 30 or 90) this range is exactly, or null for a custom range. */
    public function preset(?CarbonImmutable $now = null): ?int
    {
        foreach (self::PRESETS as $days) {
            $candidate = self::last($days, $now);
            if ($candidate->from === $this->from && $candidate->to === $this->to) {
                return $days;
            }
        }

        return null;
    }

    /** A short key for caches and logs. */
    public function key(): string
    {
        return $this->from.'_'.$this->to;
    }
}
