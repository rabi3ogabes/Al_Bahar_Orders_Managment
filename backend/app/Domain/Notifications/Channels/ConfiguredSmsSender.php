<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Contracts\SmsSender;
use App\Domain\Notifications\Providers\MessagingGateway;
use App\Domain\Notifications\Providers\MessagingProviders;
use App\Support\DomainException;

/**
 * The SMS sender of the app: the provider the owner set up in the dashboard when there is one, otherwise exactly what the server was
 * set up with before (SMS_DRIVER=http, or nothing). A delivery that fails is recorded without the message text and raised as before.
 */
class ConfiguredSmsSender implements SmsSender
{
    public function __construct(private readonly MessagingProviders $providers, private readonly MessagingGateway $gateway) {}

    public function send(string $phoneE164, string $message, string $category = 'transactional'): array
    {
        if (! $this->providers->configured('sms')) {
            return $this->legacy()->send($phoneE164, $message, $category);
        }
        $result = $this->gateway->send('sms', $phoneE164, $message);
        if ($result === null || ! $result['ok']) {
            $this->gateway->record('sms', $this->providers->chosen('sms'), $category === 'otp' ? 'otp' : 'message', $phoneE164, $result ?? ['status' => 'failed', 'code' => 'not_configured']);
            throw new DomainException('sms_failed', null, 502, ['status' => $result['http'] ?? 0]);
        }

        return ['status' => 'sent', 'provider_message_id' => $result['id']];
    }

    public function name(): string
    {
        return $this->providers->chosen('sms') !== 'none' ? $this->providers->chosen('sms') : $this->legacy()->name();
    }

    private function legacy(): SmsSender
    {
        return config('services.sms.driver') === 'http' ? new HttpSmsSender : new NullSmsSender;
    }
}
