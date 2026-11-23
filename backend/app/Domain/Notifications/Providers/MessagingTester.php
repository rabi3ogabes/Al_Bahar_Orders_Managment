<?php

namespace App\Domain\Notifications\Providers;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\MessageProviderEvent;
use App\Support\DomainException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "Send test message": one real message to a number an admin types, with the provider's own answer and delivery status kept. A test the
 * admin confirms as received on the phone is the proof that lets the owner turn the test sign-in codes off.
 */
class MessagingTester
{
    public function __construct(private readonly MessagingGateway $gateway, private readonly MessagingProviders $providers) {}

    public function send(string $channel, string $toE164, User $by): MessageProviderEvent
    {
        $key = 'messaging-test:'.$by->getKey();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw DomainException::make('too_many_requests', 429);
        }
        RateLimiter::hit($key, 600);
        $result = $this->gateway->send($channel, $toE164, __('messaging_providers.test_text', ['brand' => config('platform.brand.name_en')], 'en'));
        if ($result === null) {
            throw DomainException::make('sms_not_configured', 422);
        }
        $event = $this->gateway->record($channel, $result['provider'], 'test', $toE164, $result, $by->getKey());
        if ($result['ok']) {
            $this->refresh($event);
        }
        app(AuditLogger::class)->log('messaging.test_sent', $event, [], ['channel' => $channel, 'provider' => $result['provider'], 'status' => $event->status], actor: $by);

        return $event->fresh() ?? $event;
    }

    /** Asks the provider for the delivery status; a message already delivered never goes back. */
    public function refresh(MessageProviderEvent $event): MessageProviderEvent
    {
        if ($event->status === 'delivered' || $event->status === 'failed') {
            return $event;
        }
        $status = $this->gateway->status($event);
        if ($status !== null && $status !== $event->status) {
            $event->update(['status' => $status]);
        }

        return $event;
    }

    /** The admin got the message on the phone. Only a message the provider did not refuse can be confirmed. */
    public function confirmReceived(MessageProviderEvent $event, User $by): void
    {
        if ($event->purpose !== 'test' || $event->status === 'failed') {
            throw DomainException::make('invalid_state_transition', 409, ['from' => $event->status]);
        }
        $event->update(['confirmed_at' => now(), 'status' => 'delivered']);
        app(AuditLogger::class)->log('messaging.test_confirmed', $event, [], ['provider' => $event->provider], actor: $by);
    }
}
