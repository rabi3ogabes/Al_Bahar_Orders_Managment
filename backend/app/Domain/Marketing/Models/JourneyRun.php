<?php

namespace App\Domain\Marketing\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JourneyRun extends Model
{
    use HasUuids;

    protected $table = 'journey_runs';

    protected $guarded = ['id'];

    protected $casts = ['anchor_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'revenue' => 'decimal:2'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Journey, $this> */
    public function journey(): BelongsTo
    {
        return $this->belongsTo(Journey::class);
    }

    /** @return HasMany<JourneyDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(JourneyDelivery::class, 'run_id');
    }
}
