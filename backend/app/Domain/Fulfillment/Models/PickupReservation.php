<?php

namespace App\Domain\Fulfillment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PickupReservation extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected $casts = ['slot_date' => 'date', 'token_expires_at' => 'immutable_datetime', 'token_used_at' => 'immutable_datetime', 'ready_at' => 'immutable_datetime', 'collected_at' => 'immutable_datetime'];


    public function fulfillment(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Orders\Models\OrderFulfillment::class, 'order_fulfillment_id');
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Branches\Models\Branch::class);
    }
}
