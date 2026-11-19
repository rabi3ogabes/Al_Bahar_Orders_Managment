<?php

namespace App\Domain\Fulfillment\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A driver who handed a delivery back (too far, wrong vehicle, shift ending, other) or who did not answer in time (timeout) or went silent
 * (off_duty). One row per driver and delivery: that driver is not offered the same delivery again by the automatic dispatch.
 */
class DeliveryDecline extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /** The reasons a driver may give (POST dispatch/jobs/{id}/decline). The other two are written by the system. */
    public const DRIVER_REASONS = ['too_far', 'vehicle', 'shift_ending', 'other'];

    public const TIMEOUT = 'timeout';

    public const OFF_DUTY = 'off_duty';

    protected $guarded = ['id'];

    protected $casts = ['created_at' => 'immutable_datetime'];

    /** @return BelongsTo<Delivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /** @return BelongsTo<User, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }
}
