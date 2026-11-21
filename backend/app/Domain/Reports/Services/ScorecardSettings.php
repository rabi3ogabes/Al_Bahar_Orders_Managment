<?php

namespace App\Domain\Reports\Services;

use App\Domain\Settings\Services\Settings;
use Illuminate\Container\Attributes\Scoped;

/**
 * The scorecard's one setting: the MINIMUM SAMPLE. A figure built from fewer cases than this (an on-time rate over 4 deliveries, a median over 6 orders)
 * is shown as "not enough data yet" instead of a percentage. Saved through {@see Settings} (so every change is audited); a hand-edited value that is not
 * a whole number in range reads as the default.
 */
#[Scoped]
final class ScorecardSettings
{
    public const KEY = 'scorecard.min_sample';

    public const DEFAULT_MIN_SAMPLE = 10;

    public const MIN = 1;

    public const MAX = 200;

    public function __construct(private readonly Settings $settings) {}

    public function minSample(): int
    {
        $v = $this->settings->get(self::KEY, self::DEFAULT_MIN_SAMPLE);

        return is_numeric($v) && (int) $v >= self::MIN && (int) $v <= self::MAX ? (int) $v : self::DEFAULT_MIN_SAMPLE;
    }

    public function setMinSample(int $value): void
    {
        $this->settings->set(self::KEY, max(self::MIN, min(self::MAX, $value)));
    }
}
