<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * The moment a delivery step really happened. A driver whose phone had no signal presses "Picked up", "Delivered" or "Could not deliver" and
 * the app sends the step later with `acted_at`, the time he pressed (docs/guides/OFFLINE_DRIVER_AND_STAFF_APPS.md). While such a step runs, every
 * time it writes (the step's column, the history rows, the proof, the cash taken, the parcel's delivered time) is that moment instead of now();
 * the audit log keeps the moment the server received it. Outside a step, and for a request without `acted_at` (every app before the offline
 * outbox), {@see self::now()} is plain now(), so nothing changes for them.
 *
 * Bounds: not more than {@see self::FUTURE_SECONDS} ahead of the server (a phone clock a little fast is taken as now), not older than
 * {@see self::MAX_AGE_HOURS} hours, and never before the delivery's previous step ({@see self::notBefore()}), so a timeline never runs backwards.
 */
final class StepTime
{
    public const FUTURE_SECONDS = 120;

    public const MAX_AGE_HOURS = 24;

    private static ?CarbonImmutable $at = null;

    /** The time the step being recorded happened: the driver's press when the app sent one, otherwise now. */
    public static function now(): CarbonImmutable
    {
        return self::$at ?? CarbonImmutable::now();
    }

    /** Whether the step being recorded carries a time of its own (it was pressed earlier than it was received). */
    public static function backdated(): bool
    {
        return self::$at !== null;
    }

    /**
     * Runs $fn with the given step time in force (null: now, as always) and puts the previous value back afterwards, whatever happens.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public static function during(?CarbonImmutable $at, callable $fn): mixed
    {
        $previous = self::$at;
        self::$at = $at;
        try {
            return $fn();
        } finally {
            self::$at = $previous;
        }
    }

    /** A step is never recorded before the step that came before it: a phone clock a little slow, or a branch step in between, moves it up to that time. */
    public static function notBefore(?CarbonInterface $floor): void
    {
        if (self::$at !== null && $floor !== null && self::$at->lessThan($floor)) {
            self::$at = CarbonImmutable::instance($floor);
        }
    }

    /**
     * Reads `acted_at` (ISO 8601 with an offset or Z). Null when the app sent none. A time a little ahead of the server is taken as now; further ahead
     * (`acted_at_in_future`), older than a day (`acted_at_too_old`) or unreadable (`acted_at_invalid`) is refused with a 422 and a plain message.
     */
    public static function parse(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:?\d{2})$/', $value)) {
            throw self::error('acted_at_invalid');
        }
        try {
            $at = CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            throw self::error('acted_at_invalid');
        }
        $now = CarbonImmutable::now();
        if ($at->greaterThan($now->addSeconds(self::FUTURE_SECONDS))) {
            throw self::error('acted_at_in_future');
        }
        if ($at->lessThan($now->subHours(self::MAX_AGE_HOURS))) {
            throw self::error('acted_at_too_old', ['hours' => self::MAX_AGE_HOURS]);
        }

        return $at->greaterThan($now) ? $now : $at;
    }

    /** @param  array<string, scalar|null>  $context */
    private static function error(string $code, array $context = []): DomainException
    {
        return new DomainException($code, __('offline_steps.errors.'.$code, $context), 422, $context);
    }
}
