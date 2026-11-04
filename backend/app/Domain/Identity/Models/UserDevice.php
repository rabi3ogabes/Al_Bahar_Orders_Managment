<?php

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDevice extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['push_token'];

    protected $casts = ['last_seen_at' => 'immutable_datetime', 'disabled_at' => 'immutable_datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
