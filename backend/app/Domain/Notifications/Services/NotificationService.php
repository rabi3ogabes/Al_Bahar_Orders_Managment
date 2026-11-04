<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Jobs\SendNotificationJob;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Notifications\Models\NotificationPreference;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point for customer communications. Respects consent and per-channel preferences, writes the in-app
 * notification synchronously, and queues every external channel (push/SMS/WhatsApp/email) with retries + logging.
 */
class NotificationService
{
    public function __construct(private readonly TemplateRenderer $templates) {}

    /** @param array<string, scalar|null> $data placeholders; 'deep_link' is passed to push payloads */
    public function notify(User $user, string $event, array $data = [], ?array $channels = null, ?string $campaignId = null): array
    {
        if (! $user->isActive()) {
            return [];
        }
        $category = NotificationCatalog::category($event);
        $channels ??= NotificationCatalog::defaultChannels($event);
        $locale = $user->preferredLocale();
        $sent = [];

        foreach (array_unique($channels) as $channel) {
            if (! $this->allowed($user, $channel, $category)) {
                continue;
            }
            $msg = $this->templates->render($event, $channel, $locale, $data);
            if ($channel === 'in_app') {
                Notification::create(['user_id' => $user->id, 'event' => $event, 'category' => $category, 'title' => $msg['subject'],
                    'body' => $msg['body'], 'data' => array_filter(['deep_link' => $data['deep_link'] ?? null, 'reference' => $data['reference'] ?? null])]);
                $sent[] = 'in_app';

                continue;
            }
            $log = NotificationLog::create(['user_id' => $user->id, 'event' => $event, 'channel' => $channel, 'status' => 'queued', 'campaign_id' => $campaignId]);
            DB::afterCommit(fn () => SendNotificationJob::dispatch($log->id, $msg['subject'], $msg['body'], $data['deep_link'] ?? null));
            $sent[] = $channel;
        }

        return $sent;
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
        app(\App\Domain\Audit\Services\AuditLogger::class)->log('account.notification_preferences_updated', $user, [], ['preferences' => $prefs], actor: $user);
    }
}
