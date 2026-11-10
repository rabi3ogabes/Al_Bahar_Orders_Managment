<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Inventory\Models\BranchInventory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'active', 'is_default' => false];

    protected $casts = [
        'sale_starts_at' => 'immutable_datetime',
        'sale_ends_at' => 'immutable_datetime',
        'is_default' => 'boolean',
    ];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsToMany<ProductAttributeValue, $this> */
    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(ProductAttributeValue::class, 'product_variant_values')->with('attribute');
    }

    /** @return HasMany<ProductVariantBranchSetting, $this> */
    public function branchSettings(): HasMany
    {
        return $this->hasMany(ProductVariantBranchSetting::class);
    }

    /** @return HasMany<BranchInventory, $this> */
    public function inventory(): HasMany
    {
        return $this->hasMany(BranchInventory::class);
    }

    /** @return HasMany<ProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Display name: explicit translation, else attribute values joined ("Walnut / 180 cm"). */
    public function displayName(?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();
        $explicit = $this->getAttribute('name_'.$locale) ?: $this->name_en;
        if ($explicit) {
            return $explicit;
        }
        if (! $this->relationLoaded('attributeValues')) {
            return null;
        }
        $parts = $this->attributeValues->map(fn ($v) => $v->localized('value', $locale))->filter();

        return $parts->isEmpty() ? null : $parts->implode(' / ');
    }
}
