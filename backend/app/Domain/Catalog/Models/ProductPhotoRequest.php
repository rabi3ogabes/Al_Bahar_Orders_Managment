<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductPhotoRequest extends Model
{
    use HasUuids;

    public const KINDS = ['clean_background', 'studio_white', 'lifestyle', 'new_design'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'requested', 'count' => 1];

    protected $casts = ['claimed_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductImage, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(ProductImage::class, 'source_image_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return HasMany<ProductPhotoResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(ProductPhotoResult::class, 'request_id');
    }
}
