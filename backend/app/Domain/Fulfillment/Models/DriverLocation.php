<?php

namespace App\Domain\Fulfillment\Models;

use Illuminate\Database\Eloquent\Model;

class DriverLocation extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['recorded_at' => 'immutable_datetime', 'lat' => 'float', 'lng' => 'float'];
}
