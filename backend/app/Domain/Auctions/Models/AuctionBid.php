<?php

namespace App\Domain\Auctions\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuctionBid extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['placed_at' => 'immutable_datetime', 'bid_number' => 'integer'];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Identity\Models\User::class);
    }

    public function lot(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AuctionLot::class, 'auction_lot_id');
    }
}
