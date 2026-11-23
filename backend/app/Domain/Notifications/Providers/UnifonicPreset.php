<?php

namespace App\Domain\Notifications\Providers;

/** Unifonic (a Gulf SMS provider): the classic REST SMS API, signed by the application's AppSid. */
final class UnifonicPreset extends ProviderPreset
{
    public function key(): string
    {
        return 'unifonic';
    }

    public function channels(): array
    {
        return ['sms'];
    }

    public function consoleUrl(): string
    {
        return 'https://console.unifonic.com/';
    }

    public function fields(): array
    {
        return [
            ['key' => 'app_sid', 'secret' => true, 'required' => true, 'rule' => 'string|min:16|max:64', 'example' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90', 'channels' => ['sms']],
            ['key' => 'sender_id', 'secret' => false, 'required' => true, 'rule' => 'string|min:3|max:11', 'example' => 'ALBAHAR', 'channels' => ['sms']],
        ];
    }

    public function request(array $c, string $to, string $message, string $channel): array
    {
        return ['method' => 'POST', 'url' => 'https://api.unifonic.com/rest/SMS/messages', 'headers' => ['Accept' => 'application/json'], 'auth' => null, 'type' => 'form',
            'body' => ['AppSid' => $c['app_sid'], 'SenderID' => $c['sender_id'], 'Body' => $message, 'Recipient' => $this->digits($to), 'responseType' => 'JSON']];
    }

    public function parse(int $http, array $json, array $c): array
    {
        if ($http >= 200 && $http < 300 && ($json['success'] ?? false) === true) {
            return $this->result(true, $this->map((string) data_get($json, 'data.Status', 'Queued')), (string) data_get($json, 'data.MessageID', ''));
        }

        return $this->result(false, 'failed', null, isset($json['errorCode']) ? (string) $json['errorCode'] : 'http_'.$http, isset($json['message']) ? (string) $json['message'] : null);
    }

    public function statusRequest(array $c, string $id): ?array
    {
        return ['method' => 'GET', 'url' => 'https://api.unifonic.com/rest/SMS/messages/GetMessageIDStatus', 'headers' => ['Accept' => 'application/json'], 'auth' => null, 'type' => 'query',
            'body' => ['AppSid' => $c['app_sid'], 'MessageID' => $id]];
    }

    public function parseStatus(int $http, array $json): ?string
    {
        return $http === 200 && isset($json['data']['Status']) ? $this->map((string) $json['data']['Status']) : null;
    }

    private function map(string $s): string
    {
        return match (strtolower($s)) {
            'delivered' => 'delivered',
            'sent' => 'sent',
            'failed', 'undelivered', 'rejected', 'expired' => 'failed',
            default => 'queued',
        };
    }
}
