<?php

namespace App\Domain\Payments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentReconciliation extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['period_start' => 'date', 'period_end' => 'date', 'finalized_at' => 'immutable_datetime'];


    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PaymentReconciliationItem::class);
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Branches\Models\Branch::class);
    }
}
