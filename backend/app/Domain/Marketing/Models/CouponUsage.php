<?php

namespace App\Domain\Marketing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CouponUsage extends Model
{
    use HasUuids;

    protected $table = 'coupon_usage';

    protected $guarded = ['id'];

    protected $casts = ['reversed_at' => 'immutable_datetime'];
}
