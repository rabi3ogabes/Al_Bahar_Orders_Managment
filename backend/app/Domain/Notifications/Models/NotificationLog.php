<?php

namespace App\Domain\Notifications\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    use HasUuids;

    protected $table = 'notification_logs';

    protected $guarded = ['id'];

    protected $casts = ['response' => 'array', 'channels' => 'array', 'segment' => 'array', 'is_active' => 'boolean', 'is_enabled' => 'boolean',
        'sent_at' => 'immutable_datetime', 'scheduled_at' => 'immutable_datetime'];
}
