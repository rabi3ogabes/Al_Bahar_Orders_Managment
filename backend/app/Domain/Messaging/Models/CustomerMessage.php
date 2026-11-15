<?php

namespace App\Domain\Messaging\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One message to one customer on one channel, with its own result. */
class CustomerMessage extends Model
{
    use HasUuids;

    public const STATUSES = ['queued', 'sent', 'delivered', 'failed', 'skipped', 'cancelled'];

    protected $table = 'customer_messages';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'queued'];

    protected $casts = ['meta' => 'array', 'sent_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime'];

    /** @return BelongsTo<MessageBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(MessageBatch::class, 'batch_id');
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isFinal(): bool
    {
        return $this->status !== 'queued';
    }
}
