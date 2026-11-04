<?php

namespace App\Domain\Auctions\Models;

use Illuminate\Database\Eloquent\Model;

class AuctionLotEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['payload' => 'array', 'created_at' => 'immutable_datetime', 'sequence' => 'integer'];
}
