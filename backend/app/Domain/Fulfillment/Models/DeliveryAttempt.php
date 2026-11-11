<?php

namespace App\Domain\Fulfillment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DeliveryAttempt extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['attempted_at' => 'immutable_datetime'];
}
