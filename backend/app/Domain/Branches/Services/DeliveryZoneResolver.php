<?php

namespace App\Domain\Branches\Services;

use App\Domain\Branches\Models\BranchDeliveryZone;
use App\Domain\Branches\Models\DeliveryZone;
use Illuminate\Support\Collection;

/**
 * Resolves the delivery zone for an address and which branches serve it (with fee/limit configuration).
 * Priority: explicit zone on the address → Qatar zone number match → polygon containment.
 */
class DeliveryZoneResolver
{
    public function resolve(?string $zoneId, ?string $zoneNumber = null, ?float $lat = null, ?float $lng = null): ?DeliveryZone
    {
        $zones = DeliveryZone::where('is_active', true)->get();
        if ($zoneId && ($z = $zones->firstWhere('id', $zoneId))) {
            return $z;
        }
        if ($zoneNumber && ($z = $zones->first(fn (DeliveryZone $z) => $z->coversZoneNumber($zoneNumber)))) {
            return $z;
        }
        if ($lat !== null && $lng !== null) {
            return $zones->first(fn (DeliveryZone $z) => $z->contains($lat, $lng));
        }

        return null;
    }

    /** @return Collection<int, BranchDeliveryZone> active branch configurations serving the zone, best priority first */
    public function servingBranches(DeliveryZone $zone): Collection
    {
        return BranchDeliveryZone::with('branch')
            ->where('delivery_zone_id', $zone->id)->where('is_active', true)
            ->whereHas('branch', fn ($q) => $q->where('status', 'active')->where('delivery_enabled', true))
            ->orderBy('priority')->get();
    }
}
