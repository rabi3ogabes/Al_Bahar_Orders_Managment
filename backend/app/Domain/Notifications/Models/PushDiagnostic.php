<?php

namespace App\Domain\Notifications\Models;

use App\Domain\Notifications\Services\PushDiagnostics;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One report from a phone app about its push set-up (see the migration and {@see PushDiagnostics}).
 *
 * @property string $id
 * @property string $app
 * @property string $platform
 * @property string $stage
 * @property string $code
 * @property string|null $message
 * @property string|null $app_version
 * @property string|null $os_version
 * @property string $install_id
 * @property int $count
 */
class PushDiagnostic extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['report_day' => 'date', 'count' => 'integer', 'first_seen_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime'];
}
