<?php

namespace App\Domain\Notifications\Models;

use App\Domain\Notifications\Services\NotificationRules;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * What the admin changed for one notification event (a row exists only for an event that differs from the platform default).
 * Read and written through {@see NotificationRules}, never directly.
 *
 * @property string $id
 * @property string $event
 * @property bool $is_enabled
 * @property array<string, bool> $channels
 * @property string|null $updated_by
 */
class NotificationRule extends Model
{
    use HasUuids;

    protected $table = 'notification_rules';

    protected $guarded = ['id'];

    protected $casts = ['is_enabled' => 'boolean', 'channels' => 'array'];
}
