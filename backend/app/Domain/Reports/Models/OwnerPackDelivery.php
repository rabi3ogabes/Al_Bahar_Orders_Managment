<?php

namespace App\Domain\Reports\Models;

use Illuminate\Database\Eloquent\Model;

/** One recipient of one send: delivered or not, and why not. */
class OwnerPackDelivery extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['sent_at' => 'immutable_datetime'];
}
