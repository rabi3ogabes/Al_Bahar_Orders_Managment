<?php

namespace App\Domain\AppUpdates\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AppRelease extends Model
{
    use HasUuids;

    public const APPS = ['customer', 'driver', 'staff'];

    public const PLATFORMS = ['android', 'ios'];

    protected $guarded = ['id'];

    protected $attributes = ['latest_build' => 0, 'min_build' => 0];

    protected $casts = ['latest_build' => 'integer', 'min_build' => 'integer', 'forced_at' => 'immutable_datetime'];

    public function isForcing(): bool
    {
        return $this->min_build > 0;
    }
}
