<?php

namespace App\Domain\Auctions\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuctionBidder extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['terms_accepted_at' => 'immutable_datetime'];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Identity\Models\User::class);
    }

    public function auction(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }
}
