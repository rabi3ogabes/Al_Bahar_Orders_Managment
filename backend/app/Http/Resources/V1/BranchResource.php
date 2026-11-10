<?php

namespace App\Http\Resources\V1;

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Services\BranchHoursService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Branch */
class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->localized('name'),
            'description' => $this->localized('description'),
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->localized('address'),
            'area' => $this->area,
            'zone_number' => $this->zone_number,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'pickup_enabled' => $this->pickup_enabled,
            'delivery_enabled' => $this->delivery_enabled,
            'cod_enabled' => $this->cod_enabled,
            'pay_at_pickup_enabled' => $this->pay_at_pickup_enabled,
            'opening' => $this->when($this->relationLoaded('hours'), fn () => app(BranchHoursService::class)->status($this->resource)),
            'distance_km' => $this->when($this->resource->getAttribute('distance_km') !== null, fn () => round((float) $this->resource->getAttribute('distance_km'), 2)),
        ];
    }
}
