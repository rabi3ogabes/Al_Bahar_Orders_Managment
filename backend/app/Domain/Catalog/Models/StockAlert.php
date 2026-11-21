<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's request to be told when a variant is back in stock (`back_in_stock`: done after the first message) or when its price falls
 * (`price_drop`: keeps watching; `reference_price` is the price the customer last saw, and moves to the price they are told about).
 */
class StockAlert extends Model
{
    use HasUuids;

    public const BACK_IN_STOCK = 'back_in_stock';

    public const PRICE_DROP = 'price_drop';

    public const TYPES = [self::BACK_IN_STOCK, self::PRICE_DROP];

    protected $guarded = ['id'];

    protected $casts = ['notified_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];

    /** Alerts still waiting: not expired, and for a back-in-stock alert not yet fired. @param  Builder<StockAlert>  $q */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('expires_at', '>', now())->where(fn ($w) => $w->where('type', self::PRICE_DROP)->orWhereNull('notified_at'));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
