<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPriceHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'product_price_history';

    protected $guarded = ['id'];

    protected $casts = ['created_at' => 'immutable_datetime', 'sale_starts_at' => 'immutable_datetime', 'sale_ends_at' => 'immutable_datetime'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
