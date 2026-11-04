<?php

namespace App\Domain\Orders\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'active', 'fulfillment_type' => 'delivery', 'loyalty_points_to_redeem' => 0, 'abandoned_notifications' => 0];

    protected $casts = ['last_activity_at' => 'immutable_datetime', 'abandoned_notified_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'loyalty_points_to_redeem' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(UserAddress::class, 'address_id');
    }
}
