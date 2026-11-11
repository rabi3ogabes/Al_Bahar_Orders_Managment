<?php

namespace App\Domain\Fulfillment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DeliveryProof extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['captured_at' => 'immutable_datetime'];
}
