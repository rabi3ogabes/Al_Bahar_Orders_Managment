<?php

namespace App\Domain\Marketing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Referral extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['rewarded_at' => 'immutable_datetime'];
}
