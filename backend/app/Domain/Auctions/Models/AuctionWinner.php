<?php

namespace App\Domain\Auctions\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuctionWinner extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['payment_due_at' => 'immutable_datetime', 'confirmed_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime', 'defaulted_at' => 'immutable_datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(AuctionLot::class, 'auction_lot_id');
    }

    public function bid(): BelongsTo
    {
        return $this->belongsTo(AuctionBid::class, 'auction_bid_id');
    }
}
