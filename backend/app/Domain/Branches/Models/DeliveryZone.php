<?php

namespace App\Domain\Branches\Models;

use App\Domain\Shared\HasTranslations;
use App\Support\Geo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryZone extends Model
{
    use HasFactory, HasTranslations, HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['polygon' => 'array', 'is_active' => 'boolean', 'is_remote' => 'boolean'];

    /** @return HasMany<BranchDeliveryZone, $this> */
    public function branchConfigs(): HasMany
    {
        return $this->hasMany(BranchDeliveryZone::class);
    }

    /** Point-in-polygon (ray casting) for optional GeoJSON-style [[lng,lat],...] rings. */
    public function contains(float $lat, float $lng): bool
    {
        return Geo::pointInRing($lat, $lng, $this->polygon ?? []);
    }

    public function coversZoneNumber(?string $zoneNumber): bool
    {
        if (! $zoneNumber || ! $this->zone_numbers) {
            return false;
        }

        return in_array(trim($zoneNumber), array_map('trim', explode(',', $this->zone_numbers)), true);
    }
}
