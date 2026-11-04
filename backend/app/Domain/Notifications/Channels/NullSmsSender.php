<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Contracts\SmsSender;
use App\Support\Phone;
use Illuminate\Support\Facades\Log;

/**
 * Used until an SMS provider contract is signed. Records that a message was dispatched (masked recipient)
 * but NEVER the message body, so OTP codes cannot leak into logs.
 */
class NullSmsSender implements SmsSender
{
    public function send(string $phoneE164, string $message, string $category = 'transactional'): array
    {
        Log::info('sms.dispatched (null driver)', ['to' => Phone::mask($phoneE164), 'category' => $category]);

        return ['status' => 'skipped', 'provider_message_id' => null];
    }

    public function name(): string
    {
        return 'null';
    }
}
