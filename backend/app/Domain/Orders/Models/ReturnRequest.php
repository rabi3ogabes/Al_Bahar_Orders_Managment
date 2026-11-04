<?php

namespace App\Domain\Orders\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReturnRequest extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['items' => 'array', 'decided_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];


    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Identity\Models\User::class);
    }
}
