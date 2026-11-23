<?php

namespace App\Domain\Notifications\Providers;

/** Twilio Programmable Messaging: SMS and WhatsApp through the same Messages API (WhatsApp numbers carry the "whatsapp:" prefix). */
final class TwilioPreset extends ProviderPreset
{
    public function key(): string
    {
        return 'twilio';
    }

    public function channels(): array
    {
        return ['sms', 'whatsapp'];
    }

    public function consoleUrl(): string
    {
        return 'https://console.twilio.com/';
    }

    public function fields(): array
    {
        return [
            ['key' => 'account_sid', 'secret' => false, 'required' => true, 'rule' => 'regex:/^AC[0-9a-f]{32}$/i', 'example' => 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'channels' => ['sms', 'whatsapp']],
            ['key' => 'auth_token', 'secret' => true, 'required' => true, 'rule' => 'string|min:16|max:64', 'example' => '••••••••••••••••••••••••••••••••', 'channels' => ['sms', 'whatsapp']],
            ['key' => 'sms_from', 'secret' => false, 'required' => false, 'rule' => 'string|max:40', 'example' => '+15005550006  or  MGxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'channels' => ['sms']],
            ['key' => 'whatsapp_from', 'secret' => false, 'required' => false, 'rule' => 'regex:/^\+[1-9]\d{7,14}$/', 'example' => '+14155238886', 'channels' => ['whatsapp']],
        ];
    }

    public function request(array $c, string $to, string $message, string $channel): array
    {
        $whatsapp = $channel === 'whatsapp';
        $from = $whatsapp ? ['From' => 'whatsapp:'.($c['whatsapp_from'] ?? '')]
            : (str_starts_with($c['sms_from'] ?? '', 'MG') ? ['MessagingServiceSid' => $c['sms_from']] : ['From' => $c['sms_from'] ?? '']);

        return ['method' => 'POST', 'url' => "https://api.twilio.com/2010-04-01/Accounts/{$c['account_sid']}/Messages.json", 'headers' => ['Accept' => 'application/json'],
            'auth' => [$c['account_sid'], $c['auth_token']], 'type' => 'form', 'body' => ['To' => ($whatsapp ? 'whatsapp:' : '').$to] + $from + ['Body' => $message]];
    }

    public function parse(int $http, array $json, array $c): array
    {
        if ($http >= 200 && $http < 300 && isset($json['sid'])) {
            return $this->result(true, $this->map((string) ($json['status'] ?? 'queued')), (string) $json['sid']);
        }

        return $this->result(false, 'failed', null, isset($json['code']) ? (string) $json['code'] : 'http_'.$http, isset($json['message']) ? (string) $json['message'] : null);
    }

    public function statusRequest(array $c, string $id): array
    {
        return ['method' => 'GET', 'url' => "https://api.twilio.com/2010-04-01/Accounts/{$c['account_sid']}/Messages/{$id}.json", 'headers' => ['Accept' => 'application/json'],
            'auth' => [$c['account_sid'], $c['auth_token']], 'type' => 'query', 'body' => []];
    }

    public function parseStatus(int $http, array $json): ?string
    {
        return $http === 200 && isset($json['status']) ? $this->map((string) $json['status']) : null;
    }

    private function map(string $s): string
    {
        return match ($s) {
            'delivered', 'read' => 'delivered',
            'sent' => 'sent',
            'failed', 'undelivered', 'canceled' => 'failed',
            default => 'queued',
        };
    }
}
