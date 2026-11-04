<?php

namespace App\Domain\Marketing\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['expires_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'points' => 'integer', 'balance_after' => 'integer', 'remaining' => 'integer'];
}
