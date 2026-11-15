<?php

namespace App\Domain\Orders\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Domain\Payments\Models\Refund;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One staff request that changed the items of a branch's parcel while it was being prepared (reduce / replace / remove).
 * Append-only: the database refuses updates and deletes, so the audit trail of what the customer was told can never drift.
 *
 * @property string $id
 * @property string $order_id
 * @property string $order_fulfillment_id
 * @property string $branch_id
 * @property int $number
 * @property ?string $actor_id
 * @property string $reason_code
 * @property ?string $note
 * @property array<string, string> $before
 * @property array<string, string> $after
 * @property string $difference
 * @property ?string $refund_id
 * @property ?string $refund_amount
 * @property ?string $request_hash
 * @property int $loyalty_points_returned
 * @property bool $fulfillment_cancelled
 * @property bool $order_cancelled
 * @property CarbonImmutable $created_at
 */
class OrderRevision extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const REASONS = ['out_of_stock', 'not_found', 'damaged', 'quality', 'other'];

    /** The reasons for which the branch's on-hand count is also reduced: the unit is not on the shelf, whatever the system believed. */
    public const STOCK_CORRECTING_REASONS = ['out_of_stock', 'not_found'];

    protected $guarded = ['id'];

    protected $casts = ['before' => 'array', 'after' => 'array', 'fulfillment_cancelled' => 'boolean', 'order_cancelled' => 'boolean',
        'number' => 'integer', 'loyalty_points_returned' => 'integer', 'created_at' => 'immutable_datetime'];

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

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<Refund, $this> */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    /** @return HasMany<OrderRevisionLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderRevisionLine::class)->orderBy('position');
    }

    public function difference(): Money
    {
        return Money::of($this->difference);
    }
}
