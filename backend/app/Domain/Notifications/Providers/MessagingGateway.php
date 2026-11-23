<?php

namespace App\Domain\Notifications\Providers;

use App\Domain\Notifications\Models\MessageProviderEvent;
use App\Support\Phone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends one message through the provider the owner set up, with a timeout and ONE retry (only for a connection failure or a 5xx answer,
 * never for a refusal). It never logs or stores the message text: a sign-in code can be in it. Failures are written to
 * message_provider_events with the provider's own error code and a cleaned-up reason.
 */
class MessagingGateway
{
    public function __construct(private readonly MessagingProviders $providers) {}

    /**
     * @return array{ok: bool, status: string, id: string|null, code: string|null, error: string|null, http: int|null, provider: string}|null null when no provider is set up for the channel
     */
    public function send(string $channel, string $toE164, string $message): ?array
    {
        $active = $this->providers->active($channel);
        if ($active === null) {
            return null;
        }
        [$preset, $cfg] = $active;
        $res = $this->call($preset, $preset->request($cfg, $toE164, $message, $channel), fn (int $h, array $j) => $preset->parse($h, $j, $cfg));

        return $res + ['provider' => $preset->key()];
    }

    /** Asks the provider how a sent message is doing now; null when the provider has no such call or did not answer. */
    public function status(MessageProviderEvent $event): ?string
    {
        $preset = $this->providers->preset($event->provider);
        if ($preset === null || $event->provider_message_id === null || ! $this->providers->complete($event->provider, $event->channel)) {
            return null;
        }
        $req = $preset->statusRequest($this->providers->values($event->provider), $event->provider_message_id);
        if ($req === null) {
            return null;
        }
        $res = $this->call($preset, $req, fn (int $h, array $j) => ['status' => $preset->parseStatus($h, $j)]);

        return $res['status'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $req
     * @param  callable(int, array<string, mixed>): array<string, mixed>  $read
     * @return array<string, mixed>
     */
    private function call(ProviderPreset $preset, array $req, callable $read): array
    {
        if (! $this->safeUrl($req['url'])) {
            return ['ok' => false, 'status' => 'failed', 'id' => null, 'code' => 'unsafe_url', 'error' => 'The provider address must be a public https address.', 'http' => null];
        }
        try {
            $http = $this->client($req);
            $response = match ($req['type']) {
                'json' => $http->asJson()->send($req['method'], $req['url'], ['json' => $req['body']]),
                'query' => $http->send($req['method'], $req['url'], ['query' => $req['body']]),
                default => $http->asForm()->send($req['method'], $req['url'], ['form_params' => $req['body']]),
            };
        } catch (ConnectionException|RequestException $e) {
            return ['ok' => false, 'status' => 'failed', 'id' => null, 'code' => 'connection', 'error' => 'The provider did not answer in time.', 'http' => null];
        } catch (Throwable) {
            return ['ok' => false, 'status' => 'failed', 'id' => null, 'code' => 'error', 'error' => 'The message could not be sent.', 'http' => null];
        }

        $json = $this->json($response);
        $out = $read($response->status(), $json) + ['http' => $response->status()];
        if (isset($out['error'])) {
            $out['error'] = self::clean((string) $out['error']);
        }

        return $out;
    }

    private function client(array $req): PendingRequest
    {
        $c = Http::withHeaders($req['headers'])->timeout(10)->connectTimeout(5)
            ->retry(2, 300, fn (Throwable $e) => $e instanceof ConnectionException || ($e instanceof RequestException && $e->response->serverError()), throw: false);

        return $req['auth'] ? $c->withBasicAuth($req['auth'][0], $req['auth'][1]) : $c;
    }

    /** @return array<string, mixed> */
    private function json(Response $r): array
    {
        $j = $r->json();

        return is_array($j) ? $j : [];
    }

    /** A provider's words, safe to store and show: no long digit runs (a sign-in code, a phone number) and no markup. */
    public static function clean(string $text): string
    {
        $t = trim(strip_tags(preg_replace('/\d{4,}/', '••••', $text) ?? ''));

        return mb_substr($t, 0, 200);
    }

    /** Only a public https address: never localhost, a private network address or a plain http one. */
    public static function safeUrl(string $url): bool
    {
        $p = parse_url($url);
        $host = strtolower((string) ($p['host'] ?? ''));
        if (($p['scheme'] ?? '') !== 'https' || $host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return true;
    }

    public function record(string $channel, string $provider, string $purpose, string $toE164, array $result, ?string $by = null): MessageProviderEvent
    {
        return MessageProviderEvent::create(['channel' => $channel, 'provider' => $provider, 'purpose' => $purpose, 'recipient' => (string) Phone::mask($toE164),
            'status' => $result['status'] ?? 'failed', 'provider_message_id' => ($result['id'] ?? '') !== '' ? substr((string) $result['id'], 0, 100) : null, 'http_status' => $result['http'] ?? null,
            'error_code' => isset($result['code']) ? substr((string) $result['code'], 0, 64) : null,
            'error_detail' => isset($result['error']) ? self::clean((string) $result['error']) : null, 'created_by' => $by]);
    }
}
