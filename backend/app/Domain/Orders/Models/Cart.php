<?php

namespace App\Domain\Orders\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'active', 'fulfillment_type' => 'delivery', 'loyalty_points_to_redeem' => 0, 'abandoned_notifications' => 0];

    protected $casts = ['last_activity_at' => 'immutable_datetime', 'abandoned_notified_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'loyalty_points_to_redeem' => 'integer'];


    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Identity\Models\User::class);
    }

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Branches\Models\Branch::class);
    }

    public function address(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(UserAddress::class, 'address_id');
    }
}
