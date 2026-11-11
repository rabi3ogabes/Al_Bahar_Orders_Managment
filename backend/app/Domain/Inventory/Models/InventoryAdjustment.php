<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Branches\Models\Branch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryAdjustment extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'inventory_adjustments';

    protected $guarded = ['id'];

    protected $casts = ['posted_at' => 'immutable_datetime'];

    /** @return HasMany<InventoryAdjustmentItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InventoryAdjustmentItem::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
