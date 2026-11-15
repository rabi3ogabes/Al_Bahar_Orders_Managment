<?php

namespace App\Domain\Notifications\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Apple Live Activity token reported by the customer's iPhone app: the push token of a running activity (`activity`, for one order)
 * or the phone's push-to-start token (`start`). The token never leaves the server (hidden from every payload).
 *
 * @property string $id
 * @property string $user_id
 * @property string|null $order_id
 * @property string $kind
 * @property string $token
 * @property string $environment
 */
class LiveActivityToken extends Model
{
    use HasUuids;

    public const KIND_ACTIVITY = 'activity';

    public const KIND_START = 'start';

    protected $guarded = ['id'];

    protected $hidden = ['token'];

    protected $casts = ['last_seen_at' => 'immutable_datetime', 'last_pushed_at' => 'immutable_datetime', 'disabled_at' => 'immutable_datetime'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
