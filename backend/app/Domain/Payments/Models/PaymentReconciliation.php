<?php

namespace App\Domain\Payments\Models;

use App\Domain\Branches\Models\Branch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentReconciliation extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['period_start' => 'date', 'period_end' => 'date', 'finalized_at' => 'immutable_datetime'];

    /** @return HasMany<PaymentReconciliationItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PaymentReconciliationItem::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
