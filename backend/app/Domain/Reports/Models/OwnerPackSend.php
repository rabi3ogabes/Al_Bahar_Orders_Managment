<?php

namespace App\Domain\Reports\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One send of a week's owner pack on one channel: when, to how many, and how it went (see OwnerPackSender). */
class OwnerPackSend extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['finished_at' => 'immutable_datetime', 'recipients_count' => 'integer', 'sent_count' => 'integer', 'failed_count' => 'integer', 'attempts' => 'integer'];
}
