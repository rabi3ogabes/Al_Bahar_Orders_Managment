<?php

namespace App\Domain\Marketing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A named follow-up flow (abandoned_cart | birthday) made of timed steps. */
class Journey extends Model
{
    use HasUuids;

    protected $table = 'journeys';

    protected $guarded = ['id'];

    protected $casts = ['is_enabled' => 'boolean', 'settings' => 'array'];

    /** @return HasMany<JourneyStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(JourneyStep::class)->orderBy('position');
    }

    /** @return HasMany<JourneyRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(JourneyRun::class);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return ($this->settings ?? [])[$key] ?? $default;
    }
}
