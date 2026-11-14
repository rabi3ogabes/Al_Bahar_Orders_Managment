<?php

namespace App\Domain\Marketing\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JourneyDelivery extends Model
{
    use HasUuids;

    protected $table = 'journey_deliveries';

    protected $guarded = ['id'];

    protected $casts = ['sent_at' => 'immutable_datetime'];

    /** @return BelongsTo<JourneyRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(JourneyRun::class, 'run_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<JourneyStep, $this> */
    public function step(): BelongsTo
    {
        return $this->belongsTo(JourneyStep::class, 'step_id');
    }
}
