<?php

namespace App\Domain\Auctions\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuctionDeposit extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'pending'];

    protected $casts = ['paid_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];

    public function auction(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Identity\Models\User::class);
    }
}
