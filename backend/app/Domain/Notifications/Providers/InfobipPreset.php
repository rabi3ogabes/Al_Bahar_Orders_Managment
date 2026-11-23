<?php

namespace App\Domain\Notifications\Providers;

/** Infobip: SMS API v2 on the account's own host (shown in the Infobip portal as "Base URL"). */
final class InfobipPreset extends ProviderPreset
{
    public function key(): string
    {
        return 'infobip';
    }

    public function channels(): array
    {
        return ['sms'];
    }

    public function consoleUrl(): string
    {
        return 'https://portal.infobip.com/dev/api-keys';
    }

    public function fields(): array
    {
        return [
            ['key' => 'base_url', 'secret' => false, 'required' => true, 'rule' => 'regex:/^(https:\/\/)?[a-z0-9-]+(\.[a-z0-9-]+)*\.infobip\.com\/?$/i', 'example' => 'https://xxxxx.api.infobip.com', 'channels' => ['sms']],
            ['key' => 'api_key', 'secret' => true, 'required' => true, 'rule' => 'string|min:16|max:200', 'example' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4-1a2b3c4d-1a2b-3c4d-5e6f-1a2b3c4d5e6f', 'channels' => ['sms']],
            ['key' => 'sender', 'secret' => false, 'required' => true, 'rule' => 'string|min:3|max:16', 'example' => 'ALBAHAR', 'channels' => ['sms']],
        ];
    }

    public function request(array $c, string $to, string $message, string $channel): array
    {
        return ['method' => 'POST', 'url' => $this->base($c).'/sms/2/text/advanced', 'headers' => ['Authorization' => 'App '.$c['api_key'], 'Accept' => 'application/json'], 'auth' => null, 'type' => 'json',
            'body' => ['messages' => [['from' => $c['sender'], 'destinations' => [['to' => $this->digits($to)]], 'text' => $message]]]];
    }

    public function parse(int $http, array $json, array $c): array
    {
        $m = $json['messages'][0] ?? null;
        if ($http >= 200 && $http < 300 && is_array($m)) {
            $group = strtoupper((string) data_get($m, 'status.groupName', 'PENDING'));
            $status = $this->map($group);

            return $status === 'failed'
                ? $this->result(false, 'failed', (string) ($m['messageId'] ?? ''), (string) data_get($m, 'status.name', $group), (string) data_get($m, 'status.description', ''))
                : $this->result(true, $status, (string) ($m['messageId'] ?? ''));
        }

        return $this->result(false, 'failed', null, (string) data_get($json, 'requestError.serviceException.messageId', 'http_'.$http), (string) data_get($json, 'requestError.serviceException.text', ''));
    }

    public function statusRequest(array $c, string $id): ?array
    {
        return ['method' => 'GET', 'url' => $this->base($c).'/sms/2/logs', 'headers' => ['Authorization' => 'App '.$c['api_key'], 'Accept' => 'application/json'], 'auth' => null, 'type' => 'query', 'body' => ['messageId' => $id]];
    }

    public function parseStatus(int $http, array $json): ?string
    {
        $g = data_get($json, 'results.0.status.groupName');

        return $http === 200 && is_string($g) ? $this->map(strtoupper($g)) : null;
    }

    /** @param array<string, string> $c */
    private function base(array $c): string
    {
        $host = preg_replace('#^https?://#i', '', rtrim($c['base_url'] ?? '', '/')) ?? '';

        return 'https://'.$host;
    }

    private function map(string $group): string
    {
        return match ($group) {
            'DELIVERED' => 'delivered',
            'ACCEPTED' => 'sent',
            'REJECTED', 'UNDELIVERABLE', 'EXPIRED', 'CANCELED' => 'failed',
            default => 'queued',
        };
    }
}
