<?php

namespace App\Domain\Fulfillment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PickupVerification extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['items_handed_over' => 'array', 'is_alternate_collector' => 'boolean', 'created_at' => 'immutable_datetime'];
}
