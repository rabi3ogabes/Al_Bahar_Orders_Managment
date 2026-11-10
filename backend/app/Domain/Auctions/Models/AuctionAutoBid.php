<?php

namespace App\Domain\Auctions\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuctionAutoBid extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['set_at' => 'immutable_datetime'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
