<?php

namespace App\Domain\Payments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CashCollection extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['collected_at' => 'immutable_datetime', 'handed_in_at' => 'immutable_datetime'];


    public function payment(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function collector(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Identity\Models\User::class, 'collected_by');
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Branches\Models\Branch::class);
    }

    public function fulfillment(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Orders\Models\OrderFulfillment::class, 'order_fulfillment_id');
    }
}
