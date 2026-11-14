<?php

namespace App\Domain\Marketing\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string|null $referrer_reward_type
 * @property string|null $referrer_reward_value
 * @property string|null $referrer_coupon_code
 */
class Referral extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['rewarded_at' => 'immutable_datetime', 'rejected_at' => 'immutable_datetime', 'fraud_flags' => 'array',
        'referrer_reward_points' => 'integer', 'referee_reward_points' => 'integer'];

    /** @return BelongsTo<User, $this> */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function referee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referee_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function qualifyingOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'qualifying_order_id');
    }
}
