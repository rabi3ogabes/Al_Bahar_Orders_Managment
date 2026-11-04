<?php

namespace App\Domain\Marketing\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Review extends Model
{
    use HasUuids;

    protected $table = 'reviews';

    protected $guarded = ['id'];

    protected $casts = ['is_verified_purchase' => 'boolean', 'moderated_at' => 'immutable_datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(ReviewMedia::class);
    }
}
