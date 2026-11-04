<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Branches\Models\Branch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'stock_transfers';

    protected $guarded = ['id'];

    protected $casts = ['submitted_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime', 'dispatched_at' => 'immutable_datetime',
        'received_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'source_branch_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'destination_branch_id');
    }

    /** A transfer is visible to staff of either branch. */
    public function branchIds(): array
    {
        return [$this->source_branch_id, $this->destination_branch_id];
    }
}
