<?php

namespace App\Domain\Orders\Models;

use App\Domain\Branches\Models\DeliveryZone;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserAddress extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = ['is_default' => 'boolean', 'latitude' => 'float', 'longitude' => 'float'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class, 'delivery_zone_id');
    }

    /** Immutable snapshot stored on orders so later edits never change history. */
    public function snapshot(): array
    {
        return $this->only(['label', 'recipient_name', 'recipient_phone', 'zone_number', 'street_number', 'building_number', 'unit_number',
            'area', 'city', 'landmark', 'instructions', 'latitude', 'longitude']) + [
                'delivery_zone_id' => $this->delivery_zone_id,
                'delivery_zone' => $this->zone?->name_en,
                'address_id' => $this->id,
            ];
    }

    public function oneLine(): string
    {
        return collect([$this->unit_number ? 'Unit '.$this->unit_number : null, $this->building_number ? 'Bldg '.$this->building_number : null,
            $this->street_number ? 'St '.$this->street_number : null, $this->zone_number ? 'Zone '.$this->zone_number : null, $this->area, $this->city])->filter()->implode(', ');
    }
}
