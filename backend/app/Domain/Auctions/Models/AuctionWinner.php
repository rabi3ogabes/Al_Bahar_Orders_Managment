<?php

namespace App\Domain\Auctions\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuctionWinner extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['payment_due_at' => 'immutable_datetime', 'confirmed_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime', 'defaulted_at' => 'immutable_datetime'];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Identity\Models\User::class);
    }

    public function lot(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AuctionLot::class, 'auction_lot_id');
    }

    public function bid(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AuctionBid::class, 'auction_bid_id');
    }
}
