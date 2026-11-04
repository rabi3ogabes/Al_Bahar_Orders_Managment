<?php

namespace App\Domain\Fulfillment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Delivery extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['status' => 'App\Domain\Fulfillment\Enums\DeliveryStatus', 'booking_request' => 'array', 'booking_response' => 'array', 'requires_proof' => 'boolean', 'scheduled_for' => 'immutable_datetime', 'assigned_at' => 'immutable_datetime', 'picked_up_at' => 'immutable_datetime', 'out_for_delivery_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime', 'failed_at' => 'immutable_datetime', 'returned_at' => 'immutable_datetime'];


    public function fulfillment(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Orders\Models\OrderFulfillment::class, 'order_fulfillment_id');
    }

    public function driver(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Identity\Models\User::class, 'driver_id');
    }

    public function provider(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(DeliveryProvider::class, 'delivery_provider_id');
    }

    public function history(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DeliveryStatusHistory::class)->orderBy('id');
    }

    public function attempts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DeliveryAttempt::class);
    }

    public function proofs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DeliveryProof::class);
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Branches\Models\Branch::class);
    }
}
