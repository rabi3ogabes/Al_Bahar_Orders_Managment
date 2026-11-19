<?php

namespace App\Domain\Fulfillment\Services;

use App\Domain\Settings\Services\Settings;

/**
 * The few numbers the driver side of dispatch runs on. Each is a setting (group `dispatch`) with a plain default, so the owner can change it
 * without a release; a value that is not a positive whole number falls back to the default.
 *
 *  - dispatch.two_person_weight_kg  a parcel heavier than this (or any oversized line) needs two people
 *  - dispatch.accept_timeout_min    minutes a driver may take to accept a job before it is offered to someone else (0 switches it off)
 *  - dispatch.stale_duty_min        minutes without a sign of life after which a driver on duty is set off duty
 *  - dispatch.max_auto_attempts     how many drivers the system tries on its own for one delivery before it leaves it to the branch
 *  - dispatch.nearby_eta_min        the customer is told "close" when the driver is this many minutes away or fewer
 *  - dispatch.nearby_distance_m     the same, by straight-line distance, when no arrival time can be worked out
 */
final class DispatchSettings
{
    public const TWO_PERSON_WEIGHT_KG = 25;

    public const ACCEPT_TIMEOUT_MIN = 5;

    public const STALE_DUTY_MIN = 20;

    public const MAX_AUTO_ATTEMPTS = 3;

    public const NEARBY_ETA_MIN = 10;

    public const NEARBY_DISTANCE_M = 1500;

    public function __construct(private readonly Settings $settings) {}

    public function twoPersonWeightKg(): float
    {
        return (float) $this->whole('dispatch.two_person_weight_kg', self::TWO_PERSON_WEIGHT_KG);
    }

    /** 0 = the watchdog never takes a job back from a silent driver. */
    public function acceptTimeoutMin(): int
    {
        $raw = $this->settings->get('dispatch.accept_timeout_min', self::ACCEPT_TIMEOUT_MIN);

        return is_numeric($raw) && (int) $raw === 0 ? 0 : $this->whole('dispatch.accept_timeout_min', self::ACCEPT_TIMEOUT_MIN);
    }

    public function staleDutyMin(): int
    {
        return $this->whole('dispatch.stale_duty_min', self::STALE_DUTY_MIN);
    }

    public function maxAutoAttempts(): int
    {
        return $this->whole('dispatch.max_auto_attempts', self::MAX_AUTO_ATTEMPTS);
    }

    public function nearbyEtaMin(): int
    {
        return $this->whole('dispatch.nearby_eta_min', self::NEARBY_ETA_MIN);
    }

    public function nearbyDistanceM(): int
    {
        return $this->whole('dispatch.nearby_distance_m', self::NEARBY_DISTANCE_M);
    }

    private function whole(string $key, int $default): int
    {
        $raw = $this->settings->get($key, $default);

        return is_numeric($raw) && (int) $raw >= 1 ? (int) $raw : $default;
    }
}
