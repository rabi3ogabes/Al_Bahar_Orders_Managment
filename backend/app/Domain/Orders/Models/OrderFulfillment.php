<?php

namespace App\Domain\Orders\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\DeliverySlot;
use App\Domain\Branches\Models\DeliveryZone;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\PickupReservation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderFulfillment extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['status' => 'App\Domain\Orders\Enums\FulfillmentStatus', 'slot_date' => 'date', 'is_express' => 'boolean', 'accepted_at' => 'immutable_datetime', 'ready_at' => 'immutable_datetime', 'dispatched_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime', 'picked_up_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_fulfillment_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class, 'delivery_zone_id');
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(DeliverySlot::class, 'delivery_slot_id');
    }

    public function pickup(): HasOne
    {
        return $this->hasOne(PickupReservation::class, 'order_fulfillment_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'order_fulfillment_id');
    }

    public function activeDelivery(): ?Delivery
    {
        $deliveries = $this->relationLoaded('deliveries') ? $this->deliveries : $this->deliveries()->get();

        return $deliveries->sortByDesc('created_at')->first(fn ($d) => ! in_array($d->status->value, ['cancelled', 'returned'], true)) ?? $deliveries->sortByDesc('created_at')->first();
    }
}
