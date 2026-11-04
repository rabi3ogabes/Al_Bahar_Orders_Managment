<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Contracts\SmsSender;
use App\Support\DomainException;
use Illuminate\Support\Facades\Http;

/**
 * Generic JSON-over-HTTPS SMS gateway adapter (most Qatar aggregators — e.g. Ooredoo/Vodafone resellers,
 * Unifonic, Twilio-compatible proxies — accept this shape). Endpoint, auth header and field names are
 * configured in config/services.php → sms.http, so switching provider needs no code change.
 */
class HttpSmsSender implements SmsSender
{
    public function send(string $phoneE164, string $message, string $category = 'transactional'): array
    {
        $cfg = config('services.sms.http');
        if (empty($cfg['url'])) {
            throw new DomainException('sms_not_configured', 'SMS gateway is not configured.', 503);
        }
        $fields = $cfg['fields'];
        $response = Http::timeout(10)->retry(2, 500, throw: false)
            ->withHeaders([$cfg['auth_header'] => $cfg['auth_value']])
            ->post($cfg['url'], [
                $fields['to'] => $phoneE164,
                $fields['message'] => $message,
                $fields['sender'] => config('services.sms.sender_id'),
            ]);

        if (! $response->successful()) {
            throw new DomainException('sms_failed', 'SMS provider rejected the message.', 502, ['status' => $response->status()]);
        }

        return ['status' => 'sent', 'provider_message_id' => (string) data_get($response->json(), $fields['message_id'] ?? 'id')];
    }

    public function name(): string
    {
        return 'http';
    }
}
