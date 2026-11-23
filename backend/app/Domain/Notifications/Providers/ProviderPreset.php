<?php

namespace App\Domain\Notifications\Providers;

/**
 * One SMS / WhatsApp provider: the fields the owner types (with a hint and a fake example), the request it sends, how to read the answer
 * and, where the provider offers it, how to ask for the delivery status. Pure: no network, no settings, so the exact request is testable.
 *
 * A request is ['method', 'url', 'headers', 'auth' => [user, password]|null, 'type' => form|json|query, 'body' => array].
 * A result is ['ok', 'status' (queued|sent|delivered|failed), 'id', 'code', 'error'].
 */
abstract class ProviderPreset
{
    abstract public function key(): string;

    /** @return list<string> */
    abstract public function channels(): array;

    abstract public function consoleUrl(): string;

    /**
     * @return list<array{key: string, secret: bool, required: bool, rule: string, example: string, channels: list<string>}> the label is
     *                                                                                                                       lang messaging_providers.f.<provider>.<key>, its hint messaging_providers.h.<provider>.<key>
     */
    abstract public function fields(): array;

    /**
     * @param  array<string, string>  $c  the saved fields
     * @return array{method: string, url: string, headers: array<string, string>, auth: array{0: string, 1: string}|null, type: string, body: array<string, mixed>}
     */
    abstract public function request(array $c, string $to, string $message, string $channel): array;

    /**
     * @param  array<string, mixed>  $json
     * @param  array<string, string>  $c
     * @return array{ok: bool, status: string, id: string|null, code: string|null, error: string|null}
     */
    abstract public function parse(int $http, array $json, array $c): array;

    /**
     * @param  array<string, string>  $c
     * @return array{method: string, url: string, headers: array<string, string>, auth: array{0: string, 1: string}|null, type: string, body: array<string, mixed>}|null
     */
    public function statusRequest(array $c, string $id): ?array
    {
        return null;
    }

    /** @param array<string, mixed> $json */
    public function parseStatus(int $http, array $json): ?string
    {
        return null;
    }

    protected function digits(string $e164): string
    {
        return ltrim($e164, '+');
    }

    /** @return array{ok: bool, status: string, id: string|null, code: string|null, error: string|null} */
    protected function result(bool $ok, string $status, ?string $id = null, ?string $code = null, ?string $error = null): array
    {
        return ['ok' => $ok, 'status' => $status, 'id' => $id, 'code' => $code, 'error' => $error];
    }
}
