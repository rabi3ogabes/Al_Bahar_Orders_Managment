<?php

namespace App\Domain\Orders\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OrderFulfillment extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['status' => 'App\Domain\Orders\Enums\FulfillmentStatus', 'slot_date' => 'date', 'is_express' => 'boolean', 'accepted_at' => 'immutable_datetime', 'ready_at' => 'immutable_datetime', 'dispatched_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime', 'picked_up_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];


    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Branches\Models\Branch::class);
    }

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_fulfillment_id');
    }

    public function zone(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Branches\Models\DeliveryZone::class, 'delivery_zone_id');
    }

    public function slot(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Branches\Models\DeliverySlot::class, 'delivery_slot_id');
    }

    public function pickup(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Domain\Fulfillment\Models\PickupReservation::class, 'order_fulfillment_id');
    }

    public function deliveries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Domain\Fulfillment\Models\Delivery::class, 'order_fulfillment_id');
    }

    public function activeDelivery(): ?\App\Domain\Fulfillment\Models\Delivery
    {
        $deliveries = $this->relationLoaded('deliveries') ? $this->deliveries : $this->deliveries()->get();

        return $deliveries->sortByDesc('created_at')->first(fn ($d) => ! in_array($d->status->value, ['cancelled', 'returned'], true)) ?? $deliveries->sortByDesc('created_at')->first();
    }
}
