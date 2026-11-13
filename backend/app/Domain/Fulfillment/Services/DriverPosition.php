<?php

namespace App\Domain\Fulfillment\Services;

use App\Domain\Fulfillment\Models\DriverLocation;
use App\Domain\Fulfillment\Models\DriverProfile;
use App\Support\BusinessTime;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * The one shape of "where a driver is" used by every map surface (staff fleet, customer tracking, partner page) and
 * mirrored by the driver.moved realtime payloads, so a client needs a single parser:
 * lat, lng, heading (degrees), speed_kmh, accuracy_m, at (when the GPS fix was taken), age_seconds, stale.
 */
final class DriverPosition
{
    /** @return array{lat: float, lng: float, heading: ?int, speed_kmh: ?float, accuracy_m: ?int, at: ?string, age_seconds: ?int, stale: bool}|null */
    public static function of(?DriverProfile $p): ?array
    {
        if ($p === null || $p->last_lat === null || $p->last_lng === null) {
            return null;
        }

        return self::shape((float) $p->last_lat, (float) $p->last_lng, $p->last_heading, $p->last_speed_kmh, $p->last_accuracy_m, $p->last_fix_at ?? $p->last_seen_at);
    }

    /** The same shape from a stored breadcrumb. @return array{lat: float, lng: float, heading: ?int, speed_kmh: ?float, accuracy_m: ?int, at: ?string, age_seconds: ?int, stale: bool} */
    public static function ofBreadcrumb(DriverLocation $l): array
    {
        return self::shape((float) $l->lat, (float) $l->lng, $l->heading, $l->speed_kmh !== null ? (float) $l->speed_kmh : null, $l->accuracy_m, $l->recorded_at);
    }

    public static function ageSeconds(?CarbonInterface $at): ?int
    {
        return $at === null ? null : max(0, now()->getTimestamp() - $at->getTimestamp());
    }

    public static function staleAfter(): int
    {
        return (int) config('platform.tracking.stale_after_seconds', 120);
    }

    /** Customers see "Ahmed", never the full name. */
    public static function firstName(?string $name): string
    {
        return Str::of((string) $name)->trim()->before(' ')->limit(40, '')->toString();
    }

    /** @return array{lat: float, lng: float, heading: ?int, speed_kmh: ?float, accuracy_m: ?int, at: ?string, age_seconds: ?int, stale: bool} */
    private static function shape(float $lat, float $lng, mixed $heading, mixed $speed, mixed $accuracy, ?CarbonInterface $at): array
    {
        $age = self::ageSeconds($at);

        return [
            'lat' => $lat, 'lng' => $lng,
            'heading' => $heading !== null ? (int) $heading : null,
            'speed_kmh' => $speed !== null ? (float) $speed : null,
            'accuracy_m' => $accuracy !== null ? (int) $accuracy : null,
            'at' => BusinessTime::iso($at), 'age_seconds' => $age,
            'stale' => $age === null || $age > self::staleAfter(),
        ];
    }
}
