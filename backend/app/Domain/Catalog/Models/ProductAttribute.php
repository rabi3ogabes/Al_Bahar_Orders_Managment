<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Shared\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductAttribute extends Model
{
    use HasTranslations;

    protected $guarded = ['id'];

    protected $casts = ['is_filterable' => 'boolean', 'is_variant_axis' => 'boolean'];

    /** @return HasMany<ProductAttributeValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class)->orderBy('sort_order');
    }
}
