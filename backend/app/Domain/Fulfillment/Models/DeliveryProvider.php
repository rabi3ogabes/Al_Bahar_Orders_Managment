<?php

namespace App\Domain\Fulfillment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DeliveryProvider extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['credentials', 'webhook_secret'];

    protected $casts = ['credentials' => 'encrypted:array', 'webhook_secret' => 'encrypted', 'settings' => 'array', 'capabilities' => 'array', 'is_active' => 'boolean'];
}
