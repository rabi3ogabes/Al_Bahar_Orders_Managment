<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductPhotoResult extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $attributes = ['decision' => 'pending'];

    /** @return BelongsTo<ProductPhotoRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ProductPhotoRequest::class, 'request_id');
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }
}
