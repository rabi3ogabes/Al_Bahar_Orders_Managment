<?php

namespace App\Domain\Marketing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string|null $kind which message of the after-delivery journey the step is: thank_you, review or suggest (added by raw SQL, so declared here for static analysis)
 */
class JourneyStep extends Model
{
    use HasUuids;

    protected $table = 'journey_steps';

    protected $guarded = ['id'];

    protected $casts = ['channels' => 'array', 'is_enabled' => 'boolean', 'delay_minutes' => 'integer', 'offset_days' => 'integer', 'position' => 'integer'];

    /** @return BelongsTo<Journey, $this> */
    public function journey(): BelongsTo
    {
        return $this->belongsTo(Journey::class);
    }

    /** @return BelongsTo<JourneyTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(JourneyTemplate::class, 'template_id');
    }
}
