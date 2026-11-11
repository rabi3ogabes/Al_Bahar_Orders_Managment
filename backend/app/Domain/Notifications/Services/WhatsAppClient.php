<?php

namespace App\Domain\Notifications\Services;

use Illuminate\Support\Facades\Http;

/** WhatsApp Business Cloud API (Meta). Marketing templates must be pre-approved in WhatsApp Manager. */
class WhatsAppClient
{
    public function configured(): bool
    {
        return (bool) (config('services.whatsapp.phone_number_id') && config('services.whatsapp.access_token'));
    }

    public function sendText(string $phoneE164, string $text): array
    {
        return Http::withToken(config('services.whatsapp.access_token'))->timeout(10)
            ->post('https://graph.facebook.com/'.config('services.whatsapp.api_version').'/'.config('services.whatsapp.phone_number_id').'/messages', [
                'messaging_product' => 'whatsapp', 'to' => ltrim($phoneE164, '+'), 'type' => 'text', 'text' => ['body' => $text],
            ])->throw()->json('messages.0') ?? [];
    }
}
