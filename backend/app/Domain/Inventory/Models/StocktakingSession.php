<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Branches\Models\Branch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StocktakingSession extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'stocktaking_sessions';

    protected $guarded = ['id'];

    protected $casts = ['submitted_at' => 'immutable_datetime', 'posted_at' => 'immutable_datetime'];

    public function items(): HasMany
    {
        return $this->hasMany(StocktakingItem::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
