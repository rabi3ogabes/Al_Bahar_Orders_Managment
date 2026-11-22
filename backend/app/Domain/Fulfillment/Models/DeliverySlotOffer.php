<?php

namespace App\Domain\Fulfillment\Models;

use App\Domain\Branches\Models\DeliverySlot;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Up to a few free delivery times offered to the customer after a delivery failed. See docs/guides/DELIVERY_FOLLOW_UPS.md.
 *
 * `open` until the customer chooses one (`chosen`), the time runs out (`expired`) or the branch arranged another time (`cancelled`).
 *
 * @property array<int, array{slot_id: string, date: string}> $options
 */
class DeliverySlotOffer extends Model
{
    use HasUuids;

    public const OPEN = 'open';

    public const CHOSEN = 'chosen';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['id'];

    protected $casts = [
        'options' => 'array', 'notify_at' => 'immutable_datetime', 'notified_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime',
        'chosen_at' => 'immutable_datetime', 'chosen_date' => 'immutable_date', 'closed_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<Delivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /** @return BelongsTo<OrderFulfillment, $this> */
    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(OrderFulfillment::class, 'order_fulfillment_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<DeliverySlot, $this> */
    public function chosenSlot(): BelongsTo
    {
        return $this->belongsTo(DeliverySlot::class, 'chosen_slot_id');
    }

    /** Still waiting for the customer: not answered, not cancelled, and its time has not run out. */
    public function isOpen(): bool
    {
        return $this->status === self::OPEN && $this->expires_at->isFuture();
    }

    /** The status as the customer and the staff read it: an open offer whose time has passed is already `expired`, even before the job has written it. */
    public function effectiveStatus(): string
    {
        return $this->status === self::OPEN && ! $this->expires_at->isFuture() ? self::EXPIRED : (string) $this->status;
    }
}
