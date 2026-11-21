<?php

namespace App\Domain\Payments\Services;

use App\Domain\Settings\Services\Settings;
use App\Support\Money;

/**
 * The few numbers and the one rule behind cash shifts (Settings > Cash, docs/guides/CASH_SHIFT.md). Each has a factory default; a value that is not
 * valid (a hand-edited row) falls back to it, so a bad setting can never block a cashier from closing the till.
 *
 *  - policy        what happens to cash received while no shift is open: `attach` (accepted, and added to the next shift opened at that branch) or
 *                  `block` (refused with a clear message until a shift is open)
 *  - noteAbove     a difference between the counted and the expected cash above this amount needs a written reason
 *  - reviewAbove   a difference above this amount also needs a second person (payments.override) to look at it; never lower than noteAbove
 *  - maxOpenHours  a shift open for longer than this shows in Needs attention
 */
final class CashShiftSettings
{
    public const POLICY_ATTACH = 'attach';

    public const POLICY_BLOCK = 'block';

    public const POLICIES = [self::POLICY_ATTACH, self::POLICY_BLOCK];

    public const POLICY_KEY = 'cash.shift_policy';

    public const NOTE_ABOVE_KEY = 'cash.shift_note_above';

    public const REVIEW_ABOVE_KEY = 'cash.shift_review_above';

    public const MAX_OPEN_HOURS_KEY = 'cash.shift_max_open_hours';

    public const DEFAULT_NOTE_ABOVE = '1.00';

    public const DEFAULT_REVIEW_ABOVE = '50.00';

    public const DEFAULT_MAX_OPEN_HOURS = 16;

    public function __construct(private readonly Settings $settings) {}

    public function policy(): string
    {
        $v = (string) $this->settings->get(self::POLICY_KEY, self::POLICY_ATTACH);

        return in_array($v, self::POLICIES, true) ? $v : self::POLICY_ATTACH;
    }

    public function blocksWithoutShift(): bool
    {
        return $this->policy() === self::POLICY_BLOCK;
    }

    public function noteAbove(): Money
    {
        return $this->money(self::NOTE_ABOVE_KEY, self::DEFAULT_NOTE_ABOVE);
    }

    public function reviewAbove(): Money
    {
        return $this->money(self::REVIEW_ABOVE_KEY, self::DEFAULT_REVIEW_ABOVE)->max($this->noteAbove());
    }

    public function maxOpenHours(): int
    {
        $v = $this->settings->get(self::MAX_OPEN_HOURS_KEY, self::DEFAULT_MAX_OPEN_HOURS);

        return is_numeric($v) && (int) $v >= 1 && (int) $v <= 72 ? (int) $v : self::DEFAULT_MAX_OPEN_HOURS;
    }

    private function money(string $key, string $default): Money
    {
        $v = $this->settings->get($key, $default);
        if (! is_string($v) && ! is_int($v)) {
            return Money::of($default);
        }
        $v = trim((string) $v);

        return preg_match('/^\d{1,6}(\.\d{1,2})?$/', $v) === 1 ? Money::of($v) : Money::of($default);
    }
}
