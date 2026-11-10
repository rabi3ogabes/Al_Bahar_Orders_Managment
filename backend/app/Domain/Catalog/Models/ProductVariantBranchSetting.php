<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Branches\Models\Branch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariantBranchSetting extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'is_enabled' => 'boolean',
        'is_visible' => 'boolean',
        'sale_starts_at' => 'immutable_datetime',
        'sale_ends_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
