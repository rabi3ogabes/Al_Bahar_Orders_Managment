<?php

namespace App\Domain\Deals\Models;

use App\Domain\Orders\Models\Order;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The units one placed order took from one deal product. `released_at` is set once (cancel, expiry, adjustment of every unit): the units then
 * count for nobody again.
 *
 * @property string $id
 * @property int $units
 */
class DealRedemption extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['units' => 'integer', 'unit_savings' => 'decimal:2', 'released_at' => 'immutable_datetime'];

    /** @return BelongsTo<Deal, $this> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** @return BelongsTo<DealProduct, $this> */
    public function dealProduct(): BelongsTo
    {
        return $this->belongsTo(DealProduct::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
