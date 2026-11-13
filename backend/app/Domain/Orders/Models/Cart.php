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

    public const STAFF_DRAFT = 'staff_draft';

    protected $casts = ['staff_options' => 'array', 'last_activity_at' => 'immutable_datetime', 'abandoned_notified_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'loyalty_points_to_redeem' => 'integer'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CartItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<UserAddress, $this> */
    public function address(): BelongsTo
    {
        return $this->belongsTo(UserAddress::class, 'address_id');
    }
}
