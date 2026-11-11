<?php

namespace App\Domain\Marketing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OrderCoupon extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['free_delivery' => 'boolean'];
}
