<?php

namespace App\Domain\Fulfillment\Services;

use App\Support\Geo;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * What the driver app may send along with a step: where he stands (`latitude`, `longitude`, `accuracy` in metres), and, for a failed attempt,
 * a photo. Everything here is optional: a delivery is never refused for lack of GPS (a driver in a lift lobby has none). A value that is out
 * of range is refused (422); the "no fix" pair 0,0 is ignored, not stored.
 */
final class DriverGps
{
    public const RULES = [
        'latitude' => 'nullable|numeric|between:-90,90|required_with:longitude',
        'longitude' => 'nullable|numeric|between:-180,180|required_with:latitude',
        'accuracy' => 'nullable|numeric|between:0,32767',
    ];

    /** @return array{latitude: float, longitude: float, accuracy: ?int}|null */
    public static function from(Request $request): ?array
    {
        $d = $request->validate(self::RULES);
        if (! isset($d['latitude'], $d['longitude'])) {
            return null;
        }
        $lat = (float) $d['latitude'];
        $lng = (float) $d['longitude'];
        if (! Geo::validPoint($lat, $lng)) {
            return null;
        }

        return ['latitude' => $lat, 'longitude' => $lng, 'accuracy' => isset($d['accuracy']) ? (int) round((float) $d['accuracy']) : null];
    }

    /**
     * The input of the driver's "could not deliver" step, validated: a reason from the list, a note (required for `other` and `damaged`: the branch
     * must be able to tell what happened), an optional photo of the door or the damage, and an optional position.
     *
     * @return array{reasonCode: string, notes: ?string, photo: ?UploadedFile, gps: array{latitude: float, longitude: float, accuracy: ?int}|null}
     */
    public static function failure(Request $request): array
    {
        $d = $request->validate([
            'reason_code' => 'required|in:customer_unreachable,customer_absent,wrong_address,refused,damaged,access_denied,other',
            'notes' => 'nullable|string|max:500|required_if:reason_code,other,damaged',
            'photo' => 'nullable|file|mimetypes:image/jpeg,image/png,image/webp|max:8192',
        ] + self::RULES);
        $notes = isset($d['notes']) ? trim((string) $d['notes']) : '';
        $photo = $request->file('photo');

        return ['reasonCode' => (string) $d['reason_code'], 'notes' => $notes === '' ? null : $notes, 'photo' => $photo instanceof UploadedFile ? $photo : null, 'gps' => self::from($request)];
    }
}
