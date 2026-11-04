<?php

namespace App\Domain\Auctions\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuctionTranslation extends Model
{
    use HasUuids;

    protected $guarded = ['id'];
}
