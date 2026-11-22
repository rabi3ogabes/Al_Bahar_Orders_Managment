<?php

namespace App\Domain\Marketing\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Shared\HasTranslations;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Promotion extends Model
{
    use HasTranslations, HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'is_active' => 'boolean', 'is_stackable' => 'boolean', 'requires_coupon' => 'boolean', 'first_order_only' => 'boolean', 'applies_to_auctions' => 'boolean'];

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'promotion_products');
    }

    /** @return BelongsToMany<Category, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'promotion_categories');
    }

    /** Products the promotion never discounts (docs/guides/PROMOTION_EXCLUSIONS.md). @return BelongsToMany<Product, $this> */
    public function excludedProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'promotion_excluded_products');
    }

    /** Categories (with their sub-categories) the promotion never discounts. @return BelongsToMany<Category, $this> */
    public function excludedCategories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'promotion_excluded_categories');
    }

    /** Brands the promotion never discounts. @return BelongsToMany<Brand, $this> */
    public function excludedBrands(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class, 'promotion_excluded_brands');
    }

    /** Does the promotion leave anything out? */
    public function hasExclusions(): bool
    {
        return $this->excludedProducts->isNotEmpty() || $this->excludedCategories->isNotEmpty() || $this->excludedBrands->isNotEmpty();
    }

    /** @return BelongsToMany<Branch, $this> */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'promotion_branches');
    }

    /** @return HasMany<Coupon, $this> */
    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class);
    }
}
