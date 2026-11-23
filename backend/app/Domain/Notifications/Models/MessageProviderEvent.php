<?php

namespace App\Domain\Notifications\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One answer of the SMS / WhatsApp provider: a dashboard test message, or a failed sign-in code delivery. No message text, masked recipient.
 *
 * @property string $id
 * @property string $channel
 * @property string $provider
 * @property string $purpose
 * @property string $recipient
 * @property string $status
 * @property string|null $provider_message_id
 * @property int|null $http_status
 * @property string|null $error_code
 * @property string|null $error_detail
 * @property CarbonImmutable|null $confirmed_at
 */
class MessageProviderEvent extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['confirmed_at' => 'immutable_datetime', 'http_status' => 'integer'];
}
