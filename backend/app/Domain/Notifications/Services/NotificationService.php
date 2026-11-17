<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Events\NotificationCreated;
use App\Domain\Notifications\Jobs\SendNotificationJob;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Support\BusinessTime;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point for customer communications. Respects consent and per-channel preferences, writes the in-app
 * notification synchronously, and queues every external channel (push/SMS/WhatsApp/email) with retries + logging.
 * The admin's notification controls ({@see NotificationRules}) decide FIRST what may be sent at all: an event switched off sends and stores
 * nothing, a channel switched off is skipped; consent and preferences (allowed()) are applied on top, so a rule never adds a send a customer opted out of.
 */
class NotificationService
{
    public function __construct(private readonly TemplateRenderer $templates, private readonly NotificationRules $rules) {}

    /**
     * @param  array<string, mixed>  $data  placeholders; 'deep_link' is passed to push payloads; 'by_locale' (locale => placeholders) overrides
     *                                      placeholders per language, for content that exists in several languages (campaigns)
     */
    public function notify(User $user, string $event, array $data = [], ?array $channels = null, ?string $campaignId = null): array
    {
        if (! $user->isActive() || ! $this->rules->enabled($event)) {
            return [];
        }
        $category = NotificationCatalog::category($event);
        // A caller that names its own channels keeps them (a rule can only switch some off); with the event's defaults a channel the admin switched on is added too.
        $channels = $this->rules->effectiveChannels($event, $channels ?? NotificationCatalog::defaultChannels($event), allowAdditions: $channels === null);
        $locale = $user->preferredLocale();
        $sent = [];
        $inAppId = null;
        // In-app first, so push payloads can reference the in-app notification (tap → mark read).
        $channels = array_values(array_unique($channels));
        $ordered = $channels;
        usort($ordered, fn ($a, $b) => ($b === 'in_app') <=> ($a === 'in_app'));

        foreach ($ordered as $channel) {
            if (! $this->allowed($user, $channel, $category)) {
                continue;
            }
            if ($channel === 'push' && $this->liveCardCovers($user, $event)) {
                continue; // the live order card on the phone says the same thing: one alert, not two
            }
            $msg = $this->templates->render($event, $channel, $locale, $data);
            if ($channel === 'in_app') {
                $entry = Notification::create(['user_id' => $user->id, 'event' => $event, 'category' => $category, 'title' => $msg['subject'],
                    'body' => $msg['body'], 'data' => array_filter(['deep_link' => $data['deep_link'] ?? null, 'reference' => $data['reference'] ?? null])]);
                $inAppId = $entry->id;
                $sent[] = 'in_app';
                $this->announce($user, $entry);

                continue;
            }
            $log = NotificationLog::create(['user_id' => $user->id, 'event' => $event, 'channel' => $channel, 'status' => 'queued', 'campaign_id' => $campaignId]);
            // A push also carries every language, because each of the person's phones is sent the language of its own app.
            $texts = $channel === 'push' ? $this->inEveryLanguage($event, $channel, $data) : null;
            DB::afterCommit(fn () => SendNotificationJob::dispatch($log->id, $msg['subject'], $msg['body'], $data['deep_link'] ?? null, $inAppId, $texts));
            $sent[] = $channel;
        }

        return array_values(array_intersect($channels, $sent)); // report in the caller's channel order
    }

    /**
     * Tells the customer's open apps (socket, `private-users.{id}`) that the inbox has a new entry, so the badge and the list follow at once
     * even where Firebase push does not work. Never throws into the caller: a socket server that is down must not stop a notification.
     */
    private function announce(User $user, Notification $entry): void
    {
        rescue(fn () => event(new NotificationCreated(
            $user->id, $entry->id, $entry->event, $entry->category, (string) $entry->title, (string) $entry->body, $entry->data['deep_link'] ?? null,
            Notification::where('user_id', $user->id)->whereNull('read_at')->count(), BusinessTime::iso($entry->created_at) ?? now()->toIso8601String(),
        )), report: true);
    }

    /** @return array<string, array{subject: string, body: string}> the message rendered once per supported language */
    private function inEveryLanguage(string $event, string $channel, array $data): array
    {
        $out = [];
        $byLocale = is_array($data['by_locale'] ?? null) ? $data['by_locale'] : [];
        foreach ((array) config('platform.supported_locales') as $locale) {
            $out[$locale] = $this->templates->render($event, $channel, $locale, array_merge($data, (array) ($byLocale[$locale] ?? [])));
        }

        return $out;
    }

    /**
     * True when this order event is already announced by the customer's live order card: push is set up and the person has a phone or
     * browser that takes it. The card follows the same "order updates" push preference as the classic push (see PushNotifier).
     * With the card switched off for this step in the notification controls it covers nothing, so the classic push goes out instead.
     */
    private function liveCardCovers(User $user, string $event): bool
    {
        return in_array($event, NotificationCatalog::LIVE_CARD_EVENTS, true)
            && $this->rules->allows($event, NotificationRules::LIVE_CARD)
            && app(FcmClient::class)->configured()
            && $user->devices()->whereNull('disabled_at')->exists();
    }

    /** Consent rules: marketing needs marketing consent + opt-in; every category honours explicit opt-outs. OTP is never blocked. */
    public function allowed(User $user, string $channel, string $category): bool
    {
        if ($category === 'marketing' && ! $user->marketing_consent_at) {
            return false;
        }
        $pref = NotificationPreference::where('user_id', $user->id)->where('channel', $channel)->where('category', $category)->value('is_enabled');
        if ($pref !== null) {
            return (bool) $pref || ($category === 'transactional' && $channel === 'in_app');
        }

        return $category !== 'marketing' || in_array($channel, ['push', 'in_app'], true);
    }

    public function preferences(User $user): array
    {
        $rows = NotificationPreference::where('user_id', $user->id)->get()->keyBy(fn ($p) => $p->channel.'|'.$p->category);
        $out = [];
        foreach (NotificationCatalog::CHANNELS as $channel) {
            foreach (['transactional', 'marketing', 'auction'] as $category) {
                $out[] = ['channel' => $channel, 'category' => $category, 'is_enabled' => isset($rows[$channel.'|'.$category])
                    ? (bool) $rows[$channel.'|'.$category]->is_enabled : $this->allowed($user, $channel, $category)];
            }
        }

        return $out;
    }

    public function setPreferences(User $user, array $prefs): void
    {
        foreach ($prefs as $p) {
            NotificationPreference::updateOrCreate(['user_id' => $user->id, 'channel' => $p['channel'], 'category' => $p['category']], ['is_enabled' => (bool) $p['is_enabled']]);
        }
        app(AuditLogger::class)->log('account.notification_preferences_updated', $user, [], ['preferences' => $prefs], actor: $user);
    }
}
