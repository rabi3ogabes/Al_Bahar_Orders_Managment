<?php

namespace App\Domain\Marketing\Models;

use App\Domain\Shared\HasTranslations;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    use HasTranslations, HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'is_active' => 'boolean', 'is_stackable' => 'boolean', 'requires_coupon' => 'boolean', 'first_order_only' => 'boolean', 'applies_to_auctions' => 'boolean'];


    public function products(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(\App\Domain\Catalog\Models\Product::class, 'promotion_products');
    }

    public function categories(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(\App\Domain\Catalog\Models\Category::class, 'promotion_categories');
    }

    public function branches(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(\App\Domain\Branches\Models\Branch::class, 'promotion_branches');
    }

    public function coupons(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Coupon::class);
    }
}
