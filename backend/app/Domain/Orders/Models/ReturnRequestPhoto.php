<?php

namespace App\Domain\Orders\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One photo of a return or damage claim. The file is on a private disk; it is only ever served by an authorised route. */
class ReturnRequestPhoto extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['created_at' => 'immutable_datetime', 'bytes' => 'integer', 'width' => 'integer', 'height' => 'integer'];

    /** @return BelongsTo<ReturnRequest, $this> */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class, 'return_request_id');
    }
}
