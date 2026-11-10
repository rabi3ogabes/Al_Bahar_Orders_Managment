<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Current balance projection for (branch, variant). on_hand INCLUDES reserved; available = on_hand - reserved.
 * Quantities may only be changed by InventoryLedger (guarded here against accidental generic writes).
 */
class BranchInventory extends Model
{
    use HasUuids;

    protected $table = 'branch_inventory';

    protected $guarded = ['id', 'on_hand', 'reserved'];

    protected $attributes = ['on_hand' => 0, 'reserved' => 0, 'minimum_stock' => 0];

    protected $casts = ['on_hand' => 'integer', 'reserved' => 'integer', 'minimum_stock' => 'integer', 'maximum_stock' => 'integer',
        'last_counted_at' => 'immutable_datetime', 'last_movement_at' => 'immutable_datetime'];

    /** Set by InventoryLedger while it holds the row lock. */
    public static bool $ledgerWriting = false;

    protected static function booted(): void
    {
        static::updating(function (self $row) {
            if (($row->isDirty('on_hand') || $row->isDirty('reserved')) && ! self::$ledgerWriting) {
                throw new LogicException('Stock quantities can only be changed through InventoryLedger.');
            }
        });
    }

    public function available(): int
    {
        return $this->on_hand - $this->reserved;
    }

    public function isLow(): bool
    {
        return $this->minimum_stock > 0 && $this->available() <= $this->minimum_stock;
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
