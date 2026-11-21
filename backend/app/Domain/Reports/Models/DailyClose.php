<?php

namespace App\Domain\Reports\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One branch's day, looked at and signed off by a manager (see DailyCloseService). The numbers are the day's figures as they were when it was closed. */
class DailyClose extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['business_date' => 'immutable_date', 'closed_at' => 'immutable_datetime', 'numbers' => 'array', 'flags' => 'array'];

    /** @return BelongsTo<User, $this> */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
