<?php

namespace App\Domain\Auctions\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuctionProduct extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public function variant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Catalog\Models\ProductVariant::class, 'product_variant_id');
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Branches\Models\Branch::class);
    }
}
