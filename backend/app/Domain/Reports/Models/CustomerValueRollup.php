<?php

namespace App\Domain\Reports\Models;

use Illuminate\Database\Eloquent\Model;

/** What one customer is worth in one scope ("all" branches, or one branch), rebuilt by CustomerValue. */
class CustomerValueRollup extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['first_order_at' => 'immutable_datetime', 'last_order_at' => 'immutable_datetime', 'refreshed_at' => 'immutable_datetime',
        'orders' => 'integer', 'delivered_orders' => 'integer'];
}
