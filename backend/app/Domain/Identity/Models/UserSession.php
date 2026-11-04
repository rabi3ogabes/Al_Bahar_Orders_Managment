<?php

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSession extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['refresh_token_hash'];

    protected $casts = [
        'expires_at' => 'immutable_datetime',
        'last_used_at' => 'immutable_datetime',
        'rotated_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
