<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Branches\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    use HasFactory, HasUuids, Translatable;

    public const STATUSES = ['draft', 'active', 'inactive', 'archived'];

    protected $guarded = ['id', 'rating_average', 'rating_count', 'sales_count'];

    /** Mirrors DB defaults so new models behave identically before and after refresh. */
    protected $attributes = [
        'status' => 'draft', 'currency' => 'QAR', 'available_in_all_branches' => true, 'has_variants' => false,
        'allow_sale_above_price' => false, 'is_featured' => false, 'is_bestseller' => false, 'is_new_arrival' => false,
        'requires_assembly' => false, 'assembly_service_available' => false, 'is_oversized' => false,
        'weight_unit' => 'kg', 'dimension_unit' => 'cm', 'rating_average' => 0, 'rating_count' => 0, 'sales_count' => 0,
    ];

    protected $casts = [
        'tags' => 'array',
        'specifications' => 'array',
        'sale_starts_at' => 'immutable_datetime',
        'sale_ends_at' => 'immutable_datetime',
        'published_at' => 'immutable_datetime',
        'has_variants' => 'boolean',
        'is_featured' => 'boolean',
        'is_bestseller' => 'boolean',
        'is_new_arrival' => 'boolean',
        'requires_assembly' => 'boolean',
        'assembly_service_available' => 'boolean',
        'is_oversized' => 'boolean',
        'available_in_all_branches' => 'boolean',
        'allow_sale_above_price' => 'boolean',
        'rating_average' => 'float',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return HasMany<ProductTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(ProductTranslation::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order');
    }

    /** @return HasOne<ProductVariant, $this> */
    public function defaultVariant(): HasOne
    {
        return $this->hasOne(ProductVariant::class)->where('is_default', true);
    }

    /** @return HasMany<ProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    /** @return HasMany<ProductVideo, $this> */
    public function videos(): HasMany
    {
        return $this->hasMany(ProductVideo::class)->orderBy('sort_order');
    }

    /** @return HasMany<ProductBranchSetting, $this> */
    public function branchSettings(): HasMany
    {
        return $this->hasMany(ProductBranchSetting::class);
    }

    /** @return HasOne<ProductWarranty, $this> */
    public function warranty(): HasOne
    {
        return $this->hasOne(ProductWarranty::class);
    }

    /** @return BelongsToMany<self, $this> */
    public function related(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'product_related', 'product_id', 'related_product_id')->withPivot(['type', 'sort_order'])
            ->wherePivot('type', 'related')->orderByPivot('sort_order');
    }

    /** Products the admin suggests next to this one ("Complete the set"), in the admin's order. @return BelongsToMany<self, $this> */
    public function upsells(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'product_related', 'product_id', 'related_product_id')->withPivot(['type', 'sort_order'])
            ->wherePivot('type', 'upsell')->orderByPivot('sort_order')->orderBy('products.id');
    }

    /** @return HasMany<ProductPriceHistory, $this> */
    public function priceHistory(): HasMany
    {
        return $this->hasMany(ProductPriceHistory::class)->latest('created_at');
    }

    /** Products the customer may see at a branch (enabled + visible there). */
    public function scopeVisibleInBranch(Builder $q, ?string $branchId): Builder
    {
        if (! $branchId) {
            return $q;
        }

        return $q->where(function ($w) use ($branchId) {
            $w->where(fn ($a) => $a->where('available_in_all_branches', true)
                ->whereNotExists(fn ($s) => $s->from('product_branch_settings')->whereColumn('product_id', 'products.id')->where('branch_id', $branchId)
                    ->where(fn ($x) => $x->where('is_enabled', false)->orWhere('is_visible', false))))
                ->orWhereExists(fn ($s) => $s->from('product_branch_settings')->whereColumn('product_id', 'products.id')->where('branch_id', $branchId)
                    ->where('is_enabled', true)->where('is_visible', true));
        });
    }

    public function primaryImage(): ?ProductImage
    {
        $images = $this->relationLoaded('images') ? $this->images : $this->images()->get();

        return $images->first();
    }
}
