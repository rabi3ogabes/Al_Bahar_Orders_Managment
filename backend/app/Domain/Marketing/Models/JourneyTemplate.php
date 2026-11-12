<?php

namespace App\Domain\Marketing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Reusable AR/EN message (push, in-app, WhatsApp) with an optional personal coupon. */
class JourneyTemplate extends Model
{
    use HasUuids;

    protected $table = 'journey_templates';

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean', 'coupon_valid_hours' => 'integer'];
}
