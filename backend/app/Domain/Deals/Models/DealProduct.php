<?php

namespace App\Domain\Deals\Models;

use App\Domain\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One product inside a deal: its own quantity and limits. `quantity_sold` is moved only by DealService (claim / release) under a row lock,
 * and the database refuses a value above `quantity_total`.
 *
 * @property string $id
 * @property string $deal_id
 * @property string $product_id
 */
class DealProduct extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $attributes = ['quantity_sold' => 0, 'sort_order' => 0];

    protected $casts = ['deal_price' => 'decimal:2', 'quantity_total' => 'integer', 'quantity_sold' => 'integer', 'limit_per_order' => 'integer', 'limit_per_customer' => 'integer', 'sort_order' => 'integer'];

    /** @return BelongsTo<Deal, $this> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<DealRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(DealRedemption::class);
    }

    /** Units still on offer, or null when the product is unlimited. */
    public function remaining(): ?int
    {
        return $this->quantity_total === null ? null : max(0, $this->quantity_total - $this->quantity_sold);
    }

    /** The limit per order that applies to this product: its own, else the deal's (null = none). */
    public function perOrderLimit(Deal $deal): ?int
    {
        return $this->limit_per_order ?? $deal->limit_per_order;
    }

    /** The limit per customer account that applies to this product: its own, else the deal's (null = none). */
    public function perCustomerLimit(Deal $deal): ?int
    {
        return $this->limit_per_customer ?? $deal->limit_per_customer;
    }
}
