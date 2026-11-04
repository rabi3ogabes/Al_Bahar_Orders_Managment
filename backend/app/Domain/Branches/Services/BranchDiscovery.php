<?php

namespace App\Domain\Branches\Services;

use App\Domain\Branches\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Customer branch discovery. Coordinates are used only when the customer supplies them (consented). */
class BranchDiscovery
{
    public function query(array $filters = []): Builder
    {
        return Branch::active()
            ->with(['hours', 'holidays' => fn ($q) => $q->where('ends_at', '>', now())])
            ->when($filters['search'] ?? null, function ($q, $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($term)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(name_en) like ?', [$like])->orWhereRaw('name_ar like ?', [$like])
                    ->orWhereRaw('lower(area) like ?', [$like])->orWhereRaw('lower(code) like ?', [$like]));
            })
            ->when(! empty($filters['pickup']), fn ($q) => $q->where('pickup_enabled', true))
            ->when(! empty($filters['delivery']), fn ($q) => $q->where('delivery_enabled', true))
            ->orderBy('sort_order')->orderBy('name_en');
    }

    /** Branches within $radiusKm of (lat,lng), nearest first. Uses the haversine formula in SQL. */
    public function nearby(float $lat, float $lng, float $radiusKm = 30, array $filters = []): Collection
    {
        $haversine = '(6371 * acos(least(1, greatest(-1, cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude))))))';

        return $this->query($filters)
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->select('branches.*')
            ->selectRaw("$haversine as distance_km", [$lat, $lng, $lat])
            ->whereRaw("$haversine <= ?", [$lat, $lng, $lat, $radiusKm])
            ->reorder()->orderBy('distance_km')
            ->limit(50)->get();
    }

    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
