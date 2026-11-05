<?php

namespace App\Domain\Payments\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\OrderFulfillment;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashCollection extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['collected_at' => 'immutable_datetime', 'handed_in_at' => 'immutable_datetime'];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function handover(): BelongsTo
    {
        return $this->belongsTo(CashHandover::class, 'cash_handover_id');
    }

    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(OrderFulfillment::class, 'order_fulfillment_id');
    }
}
