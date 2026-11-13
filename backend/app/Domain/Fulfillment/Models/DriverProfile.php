<?php

namespace App\Domain\Fulfillment\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverProfile extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['on_duty' => 'boolean', 'last_lat' => 'float', 'last_lng' => 'float', 'last_speed_kmh' => 'float', 'last_fix_at' => 'immutable_datetime',
        'last_seen_at' => 'immutable_datetime', 'last_assigned_at' => 'immutable_datetime'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
