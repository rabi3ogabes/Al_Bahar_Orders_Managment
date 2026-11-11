<?php

namespace App\Domain\Fulfillment\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'delivery_status_history';

    protected $guarded = ['id'];

    protected $casts = ['created_at' => 'immutable_datetime'];
}
