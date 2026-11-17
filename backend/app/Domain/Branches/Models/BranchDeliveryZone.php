<?php

namespace App\Domain\Branches\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class BranchDeliveryZone extends Pivot
{
    use HasUuids;

    protected $table = 'branch_delivery_zones';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    /**
     * Two columns are no longer used (always NULL) and are never sent out: `free_delivery_threshold` (free delivery now only comes from a coupon code)
     * and `min_order_amount` (there is no minimum order any more, docs/api/CHECKOUT_FEES_V2.md section 2).
     */
    protected $hidden = ['free_delivery_threshold', 'min_order_amount'];

    protected $casts = ['cod_enabled' => 'boolean', 'express_enabled' => 'boolean', 'is_active' => 'boolean'];

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<DeliveryZone, $this> */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class, 'delivery_zone_id');
    }
}
