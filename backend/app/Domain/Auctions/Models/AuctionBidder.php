<?php

namespace App\Domain\Auctions\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuctionBidder extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['terms_accepted_at' => 'immutable_datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }
}
