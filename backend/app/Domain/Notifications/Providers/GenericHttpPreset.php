<?php

namespace App\Domain\Notifications\Providers;

/** Any gateway that takes one JSON POST: the address, one header that carries the key, and the names of three JSON fields. */
final class GenericHttpPreset extends ProviderPreset
{
    public function key(): string
    {
        return 'http';
    }

    public function channels(): array
    {
        return ['sms'];
    }

    public function consoleUrl(): string
    {
        return '';
    }

    public function fields(): array
    {
        return [
            ['key' => 'url', 'secret' => false, 'required' => true, 'rule' => 'url:https|max:300', 'example' => 'https://sms.example.com/api/send', 'channels' => ['sms']],
            ['key' => 'auth_header', 'secret' => false, 'required' => true, 'rule' => 'regex:/^[A-Za-z0-9-]{1,40}$/', 'example' => 'Authorization', 'channels' => ['sms']],
            ['key' => 'auth_value', 'secret' => true, 'required' => true, 'rule' => 'string|min:4|max:300', 'example' => 'Bearer abc123-fake-key', 'channels' => ['sms']],
            ['key' => 'sender', 'secret' => false, 'required' => false, 'rule' => 'string|max:16', 'example' => 'ALBAHAR', 'channels' => ['sms']],
            ['key' => 'field_to', 'secret' => false, 'required' => false, 'rule' => 'regex:/^[A-Za-z0-9_.-]{1,40}$/', 'example' => 'to', 'channels' => ['sms']],
            ['key' => 'field_message', 'secret' => false, 'required' => false, 'rule' => 'regex:/^[A-Za-z0-9_.-]{1,40}$/', 'example' => 'message', 'channels' => ['sms']],
            ['key' => 'field_sender', 'secret' => false, 'required' => false, 'rule' => 'regex:/^[A-Za-z0-9_.-]{1,40}$/', 'example' => 'sender', 'channels' => ['sms']],
            ['key' => 'field_message_id', 'secret' => false, 'required' => false, 'rule' => 'regex:/^[A-Za-z0-9_.-]{1,40}$/', 'example' => 'id', 'channels' => ['sms']],
        ];
    }

    public function request(array $c, string $to, string $message, string $channel): array
    {
        $body = [($c['field_to'] ?? '') ?: 'to' => $to, ($c['field_message'] ?? '') ?: 'message' => $message];
        if (($c['sender'] ?? '') !== '') {
            $body[($c['field_sender'] ?? '') ?: 'sender'] = $c['sender'];
        }

        return ['method' => 'POST', 'url' => $c['url'], 'headers' => [$c['auth_header'] => $c['auth_value'], 'Accept' => 'application/json'], 'auth' => null, 'type' => 'json', 'body' => $body];
    }

    public function parse(int $http, array $json, array $c): array
    {
        if ($http >= 200 && $http < 300) {
            return $this->result(true, 'sent', (string) data_get($json, ($c['field_message_id'] ?? '') ?: 'id', ''));
        }

        return $this->result(false, 'failed', null, 'http_'.$http, is_string($json['message'] ?? null) ? $json['message'] : null);
    }
}
