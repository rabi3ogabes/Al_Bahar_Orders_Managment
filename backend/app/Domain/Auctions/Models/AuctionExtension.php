<?php

namespace App\Domain\Auctions\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuctionExtension extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['previous_ends_at' => 'immutable_datetime', 'new_ends_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
}
