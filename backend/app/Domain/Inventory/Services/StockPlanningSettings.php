<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Settings\Services\Settings;

/**
 * The shop-wide numbers behind Stock planning (Settings > Stock). Each has a factory default and a safe range; a value outside the range (a hand-edited
 * row) is pulled back into it, so the planning page never divides by zero or plans for a year.
 *
 * What they mean (docs/guides/STOCK_PLANNING_AND_STOCKTAKE.md):
 *  - window_days:   how many days of orders the daily demand is worked out from;
 *  - lead_days:     how long a purchase order takes to arrive (when the item has no lead time of its own);
 *  - safety_days:   the extra days of stock kept so a late delivery or a busy week does not empty the shelf;
 *  - cycle_days:    how many days of sales one order should cover;
 *  - low_days:      "running low" means fewer than this many days of cover;
 *  - slow_days:     "slow / overstocked" means more than this many days of cover.
 */
final class StockPlanningSettings
{
    public const WINDOW_DAYS = 30;

    public const LEAD_DAYS = 14;

    public const SAFETY_DAYS = 7;

    public const CYCLE_DAYS = 30;

    public const LOW_DAYS = 14;

    public const SLOW_DAYS = 120;

    /** Fewer days than this of history (or of selling days) are never used to divide by: one busy day on a new product must not look like a trend. */
    public const MIN_BASIS_DAYS = 7;

    /** One order is a "one-off" when it is more than half of the window's units AND the window sold at least this many units (a handful of units is just a slow week). */
    public const SPIKE_MIN_UNITS = 6;

    public const KEYS = [
        'window_days' => 'stock_planning.demand_window_days',
        'lead_days' => 'stock_planning.lead_time_days',
        'safety_days' => 'stock_planning.safety_days',
        'cycle_days' => 'stock_planning.cycle_days',
        'low_days' => 'stock_planning.low_cover_days',
        'slow_days' => 'stock_planning.slow_cover_days',
    ];

    /** @var array<string, array{0: int, 1: int, 2: int}> field => [default, min, max] */
    public const LIMITS = [
        'window_days' => [self::WINDOW_DAYS, 7, 180],
        'lead_days' => [self::LEAD_DAYS, 0, 180],
        'safety_days' => [self::SAFETY_DAYS, 0, 90],
        'cycle_days' => [self::CYCLE_DAYS, 1, 180],
        'low_days' => [self::LOW_DAYS, 1, 365],
        'slow_days' => [self::SLOW_DAYS, 30, 1000],
    ];

    /** @return array<string, int> the six numbers above, keyed by field (window_days, lead_days, safety_days, cycle_days, low_days, slow_days) */
    public static function all(): array
    {
        $settings = app(Settings::class);
        $out = [];
        foreach (self::KEYS as $field => $key) {
            [$default, $min, $max] = self::LIMITS[$field];
            $value = $settings->get($key, $default);
            $out[$field] = is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
        }

        return $out;
    }
}
