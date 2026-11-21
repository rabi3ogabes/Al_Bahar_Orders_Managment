<?php

namespace App\Domain\Orders\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry of a claim's own log: what happened, who did it (customer, staff or the system) and what they wrote. Never edited. */
class ReturnRequestEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['meta' => 'array', 'created_at' => 'immutable_datetime'];

    /** @return BelongsTo<ReturnRequest, $this> */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class, 'return_request_id');
    }
}
