<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Settings\Services\Settings;
use Illuminate\Support\Facades\Http;

/**
 * Hands WhatsApp messages to an n8n workflow (webhook trigger) that talks to the WhatsApp provider.
 * Every request is signed: `X-Bahar-Signature: sha256=<hmac of "<timestamp>.<body>">` with `X-Bahar-Timestamp`, so n8n can
 * reject forged calls; n8n reports delivery back to /api/v1/integrations/n8n/delivery with the same signature scheme.
 */
class N8nClient
{
    public function __construct(private readonly Settings $settings) {}

    public function configured(): bool
    {
        return (bool) $this->settings->get('n8n.enabled', false) && $this->url() !== '';
    }

    public function url(): string
    {
        $url = trim((string) $this->settings->get('n8n.webhook_url', ''));

        return str_starts_with($url, 'https://') ? $url : '';
    }

    /** @return array{ok: bool, id?: string|null, status?: int} */
    public function send(array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $res = Http::withHeaders([
            'Content-Type' => 'application/json', 'X-Bahar-Timestamp' => $ts, 'X-Bahar-Signature' => 'sha256='.self::sign($ts, $body, $this->secret()),
        ])->timeout(10)->withBody($body, 'application/json')->post($this->url());

        return ['ok' => $res->successful(), 'id' => $res->json('id') ?? $res->json('message_id'), 'status' => $res->status()];
    }

    public static function sign(string $timestamp, string $body, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /** Verifies an inbound call from n8n (5-minute replay window). */
    public function verify(string $timestamp, string $body, string $signature): bool
    {
        $secret = $this->secret();
        if ($secret === '' || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        return hash_equals(self::sign($timestamp, $body, $secret), preg_replace('/^sha256=/', '', $signature));
    }

    private function secret(): string
    {
        return (string) $this->settings->get('n8n.secret', '');
    }
}
