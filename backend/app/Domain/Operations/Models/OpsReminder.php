<?php

namespace App\Domain\Operations\Models;

use App\Domain\Orders\Models\OrderFulfillment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An alert already sent about a branch part of an order: level new | amber | red. Unique per (fulfillment, level), which is what makes the alerts idempotent. */
class OpsReminder extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['sent_at' => 'immutable_datetime'];

    /** @return BelongsTo<OrderFulfillment, $this> */
    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(OrderFulfillment::class, 'fulfillment_id');
    }
}
