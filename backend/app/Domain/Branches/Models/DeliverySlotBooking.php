<?php

namespace App\Domain\Branches\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DeliverySlotBooking extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['slot_date' => 'date'];
}
