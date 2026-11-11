<?php

namespace App\Domain\Fulfillment\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Orders\Models\OrderFulfillment;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PickupReservation extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected $casts = ['slot_date' => 'date', 'token_expires_at' => 'immutable_datetime', 'token_used_at' => 'immutable_datetime', 'ready_at' => 'immutable_datetime', 'collected_at' => 'immutable_datetime'];

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
}
