<?php

namespace App\Domain\Orders\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OrderNote extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['is_internal' => 'boolean'];


    public function author(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Identity\Models\User::class, 'author_id');
    }
}
