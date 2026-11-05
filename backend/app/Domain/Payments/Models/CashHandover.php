<?php

namespace App\Domain\Payments\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A cash hand-in: the collector's expected cash vs the amount the branch cashier counted (see CashDrawerService). */
class CashHandover extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['variance_resolved_at' => 'immutable_datetime', 'deposited_at' => 'immutable_datetime', 'collections_count' => 'integer'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function depositor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deposited_by');
    }

    public function collections(): HasMany
    {
        return $this->hasMany(CashCollection::class);
    }

    public function hasOpenVariance(): bool
    {
        return $this->variance_status === 'open';
    }
}
