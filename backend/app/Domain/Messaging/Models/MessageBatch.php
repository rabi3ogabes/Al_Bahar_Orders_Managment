<?php

namespace App\Domain\Messaging\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\JourneyTemplate;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One send from the customers pages: a single customer or a bulk selection, the texts as sent, and the running totals. */
class MessageBatch extends Model
{
    use HasUuids;

    public const KINDS = ['reminder', 'offer', 'custom'];

    public const CHANNELS = ['notification', 'whatsapp'];

    protected $table = 'customer_message_batches';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'sending', 'recipients_count' => 0, 'queued_count' => 0, 'sent_count' => 0, 'delivered_count' => 0, 'failed_count' => 0, 'skipped_count' => 0];

    protected $casts = ['channels' => 'array', 'content' => 'array', 'audience' => 'array', 'scheduled_at' => 'immutable_datetime',
        'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];

    /** @return HasMany<CustomerMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(CustomerMessage::class, 'batch_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<JourneyTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(JourneyTemplate::class, 'template_id');
    }

    /** Recomputes the totals from the message rows (one grouped query, so the numbers can never drift) and closes the batch when nothing is left to send. */
    public function refreshCounts(): static
    {
        $by = $this->messages()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $queued = (int) ($by['queued'] ?? 0);
        $delivered = (int) ($by['delivered'] ?? 0);
        $this->forceFill([
            'queued_count' => $queued, 'sent_count' => (int) ($by['sent'] ?? 0) + $delivered, 'delivered_count' => $delivered,
            'failed_count' => (int) ($by['failed'] ?? 0), 'skipped_count' => (int) ($by['skipped'] ?? 0) + (int) ($by['cancelled'] ?? 0),
        ]);
        if ($queued === 0 && $this->status === 'sending') {
            $this->forceFill(['status' => 'done', 'completed_at' => now()]);
        }
        $this->save();

        return $this;
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['done', 'cancelled'], true);
    }
}
