<?php

namespace App\Domain\Reports\Services;

use App\Domain\Operations\Services\OpsClock;
use App\Support\Money;
use Illuminate\Support\Facades\Lang;

/**
 * How a scorecard number is written on the page, in the dashboard language: one place, so the cards, the side windows and the tests say the same
 * words. Arrows carry a direction only (a lower failure rate is not "green"): the words next to them say up, down or no change.
 */
final class ScorecardFormat
{
    /** @return string a time in minutes as plain words ("Under 1 min", "12 min", "2 h 05 min", "3 d 4 h") */
    public static function minutes(float $minutes): string
    {
        $m = (int) round($minutes);

        return $m < 1 ? __('scorecard.under_minute') : OpsClock::label($m);
    }

    /** "94%" or "94.5%" (a whole number has no decimal). */
    public static function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.').'%';
    }

    public static function number(float|int $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 1, '.', ','), '0'), '.');
    }

    /** A value of a metric of the given kind ("percent", "minutes" or "count"). */
    public static function value(string $kind, float|int $value): string
    {
        return match ($kind) {
            'percent' => self::percent((float) $value),
            'minutes' => self::minutes((float) $value),
            default => self::number($value),
        };
    }

    /**
     * The change of a number against the period before, as an arrow and words, or null when there is nothing to say (no comparison).
     *
     * @return array{direction: string, arrow: string, text: string, words: string}|null
     */
    public static function change(string $kind, ?float $change): ?array
    {
        $direction = ScorecardStats::direction($change);
        if ($direction === null || $change === null) {
            return null;
        }
        $size = abs($change);
        $text = match ($kind) {
            'percent' => __('scorecard.pts', ['n' => self::number($size)]),
            'minutes' => self::minutes($size),
            default => self::number($size),
        };
        $arrow = match ($direction) {
            'up' => '▲',
            'down' => '▼',
            default => '＝',
        };
        $words = match ($direction) {
            'up' => __('scorecard.up', ['text' => $text]),
            'down' => __('scorecard.down', ['text' => $text]),
            default => __('scorecard.same'),
        };

        return ['direction' => $direction, 'arrow' => $arrow, 'text' => $direction === 'same' ? '' : $text, 'words' => $words];
    }

    /**
     * A plain count dressed as a metric, so the same widget shows it: always "ok" (a count is a fact, not an estimate), its change the difference.
     *
     * @param  array{value: int, previous: int, change: int, change_percent: ?float}  $count
     * @return array{value: float, n: int, state: string, previous: float, previous_state: string, change: float}
     */
    public static function asMetric(array $count): array
    {
        return ['value' => (float) $count['value'], 'n' => $count['value'], 'state' => ScorecardStats::OK, 'previous' => (float) $count['previous'], 'previous_state' => ScorecardStats::OK,
            'change' => (float) $count['change']];
    }

    public static function money(string $amount): string
    {
        return Money::of($amount)->format();
    }

    /** The label of a recorded reason of a failed delivery (a code nobody has a word for reads as "Other"). */
    public static function failReason(string $code): string
    {
        return __('sales_admin.fail_'.ScorecardCauses::failReason($code));
    }

    public static function cancelReason(string $code): string
    {
        return __('scorecard.cancel.'.ScorecardCauses::cancelReason($code));
    }

    public static function claimReason(string $code): string
    {
        return Lang::has('scorecard.claim.'.$code) ? __('scorecard.claim.'.$code) : __('scorecard.claim.other');
    }

    public static function declineReason(?string $code): string
    {
        return __('scorecard.decline.'.(in_array($code, ScorecardCauses::DECLINE_REASONS, true) ? $code : 'other'));
    }
}
