<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * All timestamps are stored in UTC; business rules (opening hours, slots, reports) are evaluated in Asia/Qatar.
 */
final class BusinessTime
{
    public static function zone(): string
    {
        return config('platform.business_timezone', 'Asia/Qatar');
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::zone());
    }

    public static function local(DateTimeInterface|string|null $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse($value)->setTimezone(self::zone());
    }

    /**
     * Parse a staff-entered or client-sent time as business time (unless it carries its own offset) and return it in UTC,
     * which is what must be stored: Eloquent writes the wall-clock value without an offset.
     */
    public static function toUtc(DateTimeInterface|string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, self::zone())->utc();
    }

    /** ISO-8601 with offset, for API payloads. */
    public static function iso(DateTimeInterface|string|null $value): ?string
    {
        return self::local($value)?->toIso8601String();
    }

    public static function display(DateTimeInterface|string|null $value, string $format = 'd M Y, H:i'): ?string
    {
        return self::local($value)?->locale(app()->getLocale())->translatedFormat($format);
    }

    /** Start/end (UTC) of a local business date range, inclusive. */
    public static function dateRange(string $from, string $to): array
    {
        return [
            CarbonImmutable::parse($from, self::zone())->startOfDay()->utc(),
            CarbonImmutable::parse($to, self::zone())->endOfDay()->utc(),
        ];
    }
}
