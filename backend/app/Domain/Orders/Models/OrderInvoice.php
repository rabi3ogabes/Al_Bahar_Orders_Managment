<?php

namespace App\Domain\Orders\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OrderInvoice extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['snapshot' => 'array', 'issued_at' => 'immutable_datetime'];
}
