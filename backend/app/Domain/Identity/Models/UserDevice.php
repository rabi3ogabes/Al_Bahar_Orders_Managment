<?php

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDevice extends Model
{
    use HasUuids;

    /**
     * What a phone says about showing notifications (`notification_permission`; null = not reported, which is not the same as off).
     * Android 13+ and iOS hide every push until the person allows it.
     */
    public const PERMISSIONS = ['granted', 'denied', 'not_asked'];

    protected $guarded = ['id'];

    protected $hidden = ['push_token'];

    protected $casts = ['last_seen_at' => 'immutable_datetime', 'disabled_at' => 'immutable_datetime', 'notification_permission_at' => 'immutable_datetime'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
