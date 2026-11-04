<?php

namespace App\Domain\Marketing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LoyaltyAccount extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['balance' => 'integer', 'lifetime_earned' => 'integer'];


    public function transactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }
}
