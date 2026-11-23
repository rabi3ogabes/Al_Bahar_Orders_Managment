<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Providers\MessagingGateway;
use App\Domain\Notifications\Providers\MessagingProviders;
use App\Support\DomainException;
use Illuminate\Support\Facades\Http;

/** WhatsApp Business Cloud API (Meta). Marketing templates must be pre-approved in WhatsApp Manager. */
class WhatsAppClient
{
    public function configured(): bool
    {
        return app(MessagingProviders::class)->configured('whatsapp') || (bool) (config('services.whatsapp.phone_number_id') && config('services.whatsapp.access_token'));
    }

    public function sendText(string $phoneE164, string $text): array
    {
        // The provider the owner set up in the dashboard (Twilio) wins over the server's Meta keys.
        if (app(MessagingProviders::class)->configured('whatsapp')) {
            $r = app(MessagingGateway::class)->send('whatsapp', $phoneE164, $text);
            if ($r === null || ! $r['ok']) {
                app(MessagingGateway::class)->record('whatsapp', app(MessagingProviders::class)->chosen('whatsapp'), 'message', $phoneE164, $r ?? ['status' => 'failed', 'code' => 'not_configured']);
                throw new DomainException('whatsapp_failed', null, 502);
            }

            return ['id' => $r['id']];
        }

        return Http::withToken(config('services.whatsapp.access_token'))->timeout(10)
            ->post('https://graph.facebook.com/'.config('services.whatsapp.api_version').'/'.config('services.whatsapp.phone_number_id').'/messages', [
                'messaging_product' => 'whatsapp', 'to' => ltrim($phoneE164, '+'), 'type' => 'text', 'text' => ['body' => $text],
            ])->throw()->json('messages.0') ?? [];
    }
}
