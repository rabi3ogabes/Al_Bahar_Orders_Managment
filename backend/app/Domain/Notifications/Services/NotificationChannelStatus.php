<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Providers\MessagingProviders;

/**
 * Which delivery channels are set up on this server, so the notification controls can say "not set up yet" instead of letting the admin
 * switch on something that cannot send. It reports what the SEND path itself checks (SendNotificationJob, PushNotifier), nothing more.
 *
 *  - push: Firebase is set up and switched on (Push notifications page);
 *  - live_card: the card goes out through Firebase (Android) or Apple's key (iPhone Live Activity), so either one is enough;
 *  - sms: SMS_DRIVER=http with a gateway URL; whatsapp: the WhatsApp Cloud API keys; email: a real mailer instead of the log.
 */
class NotificationChannelStatus
{
    /** @return array<string, bool> channel => can send on this server */
    public function ready(): array
    {
        $push = app(FirebaseConfig::class)->configured();

        return [
            'push' => $push,
            'in_app' => true,
            'sms' => app(MessagingProviders::class)->configured('sms') || (config('services.sms.driver') === 'http' && filled(config('services.sms.http.url'))),
            'whatsapp' => app(WhatsAppClient::class)->configured(),
            'email' => ! in_array((string) config('mail.default'), ['log', 'array', ''], true),
            NotificationRules::LIVE_CARD => $push || app(ApnsConfig::class)->configured(),
        ];
    }

    public function isReady(string $channel): bool
    {
        return $this->ready()[$channel] ?? false;
    }
}
