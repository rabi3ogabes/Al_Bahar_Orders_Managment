<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StocktakingSession extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'stocktaking_sessions';

    protected $guarded = ['id'];

    protected $casts = ['submitted_at' => 'immutable_datetime', 'posted_at' => 'immutable_datetime'];

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StocktakingItem::class);
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Branches\Models\Branch::class);
    }
}
