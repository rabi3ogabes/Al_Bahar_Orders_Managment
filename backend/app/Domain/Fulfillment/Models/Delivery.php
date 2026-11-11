<?php

namespace App\Domain\Fulfillment\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\OrderFulfillment;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Delivery extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['status' => 'App\Domain\Fulfillment\Enums\DeliveryStatus', 'booking_request' => 'array', 'booking_response' => 'array', 'requires_proof' => 'boolean', 'scheduled_for' => 'immutable_datetime', 'assigned_at' => 'immutable_datetime', 'picked_up_at' => 'immutable_datetime', 'out_for_delivery_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime', 'failed_at' => 'immutable_datetime', 'returned_at' => 'immutable_datetime'];

    /** @return BelongsTo<OrderFulfillment, $this> */
    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(OrderFulfillment::class, 'order_fulfillment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    /** @return BelongsTo<DeliveryProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(DeliveryProvider::class, 'delivery_provider_id');
    }

    /** @return HasMany<DeliveryStatusHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(DeliveryStatusHistory::class)->orderBy('id');
    }

    /** @return HasMany<DeliveryAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class);
    }

    /** @return HasMany<DeliveryProof, $this> */
    public function proofs(): HasMany
    {
        return $this->hasMany(DeliveryProof::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
