<?php

namespace App\Domain\Reports\Services;

/**
 * The few pieces of arithmetic every scorecard number goes through, kept pure so they can be checked by hand:
 *
 *  - percentiles by linear interpolation, the same as PostgreSQL's percentile_cont (the median of 10, 20, 30, 40 is 25, never 20 or 30);
 *  - a rate is a share of a whole, as a percent with one decimal;
 *  - the MINIMUM SAMPLE rule: a number built from fewer than the minimum cases is not shown as a number at all ("not enough data yet"), because 2 late
 *    deliveries out of 3 is not a 67% problem. The cases are still counted and shown;
 *  - the change against the period before: only when BOTH periods had enough cases.
 *
 * A "metric" is the array {@see self::metric()} returns: { value, n, state, previous, previous_state, change }.
 */
final class ScorecardStats
{
    /** Enough cases: the number is shown. */
    public const OK = 'ok';

    /** Some cases, but fewer than the minimum: "not enough data yet". */
    public const FEW = 'few';

    /** No cases at all. */
    public const NONE = 'none';

    /**
     * The value at a position of a list of numbers, between its neighbours (PostgreSQL percentile_cont). Null for an empty list.
     *
     * @param  list<int|float>  $values  in any order
     */
    public static function percentile(array $values, float $p): ?float
    {
        $n = count($values);
        if ($n === 0) {
            return null;
        }
        sort($values);
        $rank = max(0.0, min(1.0, $p)) * ($n - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);

        return (float) $values[$low] + ($rank - $low) * ((float) $values[$high] - (float) $values[$low]);
    }

    /** @param  list<int|float>  $values */
    public static function median(array $values): ?float
    {
        return self::percentile($values, 0.5);
    }

    /** @param  list<int|float>  $values */
    public static function p90(array $values): ?float
    {
        return self::percentile($values, 0.9);
    }

    /** A share of a whole as a percent with one decimal; null when there is no whole. */
    public static function rate(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part * 100 / $whole, 1) : null;
    }

    /** One decimal, for minutes. */
    public static function round1(?float $value): ?float
    {
        return $value === null ? null : round($value, 1);
    }

    /** The state of a number built from $n cases: enough, too few, or none. */
    public static function state(int $n, int $min): string
    {
        return match (true) {
            $n <= 0 => self::NONE,
            $n < max(1, $min) => self::FEW,
            default => self::OK,
        };
    }

    /**
     * A number with its sample size, its place in the "enough data" rule and its change against the period before.
     *
     * @param  ?float  $value  the measure of this period (ignored unless there are enough cases)
     * @param  int  $n  the cases it is built from (the whole a rate is a share of, the readings a median is taken of)
     * @param  ?float  $previous  the same measure over the previous period
     * @param  int  $previousN  the cases of the previous period
     * @return array{value: ?float, n: int, state: string, previous: ?float, previous_state: string, change: ?float}
     */
    public static function metric(?float $value, int $n, ?float $previous, int $previousN, int $min): array
    {
        $state = self::state($n, $min);
        $previousState = self::state($previousN, $min);
        $shown = $state === self::OK ? $value : null;
        $before = $previousState === self::OK ? $previous : null;

        return [
            'value' => $shown, 'n' => $n, 'state' => $state, 'previous' => $before, 'previous_state' => $previousState,
            'change' => $shown !== null && $before !== null ? round($shown - $before, 1) : null,
        ];
    }

    /**
     * A plain count (orders handled, deliveries made): always shown, with its change against the period before as a difference. A count has no
     * minimum sample: it is a fact, not an estimate.
     *
     * @return array{value: int, previous: int, change: int, change_percent: ?float}
     */
    public static function count(int $value, int $previous): array
    {
        return ['value' => $value, 'previous' => $previous, 'change' => $value - $previous,
            'change_percent' => $previous > 0 ? round(($value - $previous) * 100 / $previous, 1) : null];
    }

    /** "up", "down" or "same": the direction of a change, for an arrow that carries no judgement (a lower failure rate is not "green"). */
    public static function direction(int|float|null $change): ?string
    {
        if ($change === null) {
            return null;
        }

        return match (true) {
            $change > 0 => 'up',
            $change < 0 => 'down',
            default => 'same',
        };
    }
}
