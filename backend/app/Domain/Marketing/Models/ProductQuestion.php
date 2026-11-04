<?php

namespace App\Domain\Marketing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProductQuestion extends Model
{
    use HasUuids;

    protected $table = 'product_questions';

    protected $guarded = ['id'];

    protected $casts = ['answered_at' => 'immutable_datetime'];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Identity\Models\User::class);
    }
}
