<?php

namespace App\Http\Resources\V1\Admin;

use App\Domain\Branches\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Branch */
class BranchAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...collect($this->resource->getAttributes())->except([])->all(),
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'pay_at_pickup_tenders' => $this->pay_at_pickup_tenders,
            'hours' => $this->whenLoaded('hours'),
            'holidays' => $this->whenLoaded('holidays'),
            'employees_count' => $this->whenCounted('employeeAssignments'),
        ];
    }
}
