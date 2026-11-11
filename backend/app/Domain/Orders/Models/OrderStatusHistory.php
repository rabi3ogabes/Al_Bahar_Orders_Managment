<?php

namespace App\Domain\Orders\Models;

use Illuminate\Database\Eloquent\Model;

class OrderStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'order_status_history';

    protected $guarded = ['id'];

    protected $casts = ['metadata' => 'array', 'created_at' => 'immutable_datetime', 'is_customer_visible' => 'boolean'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException(static::class.' records are immutable.'));
        static::deleting(fn () => throw new \LogicException(static::class.' records are immutable.'));
    }
}
