<?php

namespace App\Domain\Orders\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "We do not have X. Replace it with Y?": one question to the customer about one replaced line. While it is `waiting` the replacement is already in the
 * order (reserved for it, never dearer than what it replaces); an answer, or the deadline, settles it. See docs/guides/RETURNS_AND_SUBSTITUTIONS.md.
 */
class SubstitutionRequest extends Model
{
    use HasUuids;

    public const WAITING = 'waiting';

    /** Every status a question can end in. */
    public const ENDED = ['accepted', 'declined', 'expired_removed', 'expired_kept', 'cancelled'];

    protected $table = 'order_substitution_requests';

    protected $guarded = ['id'];

    protected $casts = ['asked_at' => 'immutable_datetime', 'deadline_at' => 'immutable_datetime', 'answered_at' => 'immutable_datetime', 'response_minutes' => 'integer'];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<OrderFulfillment, $this> */
    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(OrderFulfillment::class, 'order_fulfillment_id');
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function original(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'original_order_item_id');
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function replacement(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'replacement_order_item_id');
    }

    public function isWaiting(): bool
    {
        return $this->status === self::WAITING;
    }
}
