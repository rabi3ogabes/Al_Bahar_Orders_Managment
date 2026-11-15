<?php

namespace App\Domain\Notifications\Jobs;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserDevice;
use App\Domain\Notifications\Contracts\SmsSender;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Notifications\Services\FcmClient;
use App\Domain\Notifications\Services\WhatsAppClient;
use App\Support\Phone;
use App\Support\Redactor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/** Delivers one queued message on one channel, with exponential backoff and a redacted provider log. */
class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /**
     * @param  array<string, array{subject: string, body: string}>|null  $texts  the same message in the other languages (push only), so each phone
     *                                                                           gets the language of its own app, not only the account's
     */
    public function __construct(public readonly string $logId, public readonly string $subject, public readonly string $body, public readonly ?string $deepLink = null, public readonly ?string $notificationId = null, public readonly ?array $texts = null)
    {
        $this->onQueue('notifications');
    }

    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(SmsSender $sms, FcmClient $fcm, WhatsAppClient $whatsapp): void
    {
        $log = NotificationLog::find($this->logId);
        if (! $log || in_array($log->status, ['sent', 'delivered'], true)) {
            return;
        }
        $user = User::find($log->user_id);
        $log->increment('attempts');
        try {
            [$status, $recipient, $providerId, $response, $provider] = match ($log->channel) {
                'sms' => $this->sms($sms, $user),
                'whatsapp' => $this->whatsapp($whatsapp, $user),
                'email' => $this->email($user),
                'push' => $this->push($fcm, $user, $log),
                default => ['skipped', null, null, [], 'none'],
            };
            $log->update(['status' => $status, 'recipient' => $recipient, 'provider' => $provider, 'provider_message_id' => $providerId,
                'response' => Redactor::redact($response), 'sent_at' => in_array($status, ['sent', 'delivered'], true) ? now() : null, 'error' => null]);
        } catch (Throwable $e) {
            $log->update(['status' => $this->attempts() >= $this->tries ? 'failed' : 'queued', 'error' => substr($e->getMessage(), 0, 490)]);
            throw $e; // let the queue retry with backoff
        }
    }

    private function sms(SmsSender $sms, ?User $user): array
    {
        if (! $user?->phone) {
            return ['skipped', null, null, ['reason' => 'no_phone'], $sms->name()];
        }
        $r = $sms->send($user->phone, $this->body, 'transactional');

        return [$r['status'] === 'sent' ? 'sent' : $r['status'], Phone::mask($user->phone), $r['provider_message_id'], $r, $sms->name()];
    }

    private function whatsapp(WhatsAppClient $client, ?User $user): array
    {
        if (! $user?->phone || ! $client->configured()) {
            return ['skipped', null, null, ['reason' => $user?->phone ? 'not_configured' : 'no_phone'], 'whatsapp'];
        }
        $r = $client->sendText($user->phone, $this->body);

        return ['sent', Phone::mask($user->phone), $r['id'] ?? null, $r, 'whatsapp'];
    }

    private function email(?User $user): array
    {
        if (! $user?->email) {
            return ['skipped', null, null, ['reason' => 'no_email'], 'mail'];
        }
        Mail::raw($this->body, fn ($m) => $m->to($user->email)->subject($this->subject));

        return ['sent', Redactor::maskEmail($user->email), null, [], config('mail.default')];
    }

    /**
     * One message to each of the person's phones and browsers, each in the language of its own app. A phone FCM no longer knows is switched
     * off. When nothing was delivered and Google was only busy or unreachable the job fails, so the queue tries again later with backoff;
     * once at least one device has the message it is done, so a retry never rings the devices that already got it a second time.
     */
    private function push(FcmClient $fcm, ?User $user, NotificationLog $log): array
    {
        $devices = $user?->devices()->whereNull('disabled_at')->get() ?? collect();
        if ($devices->isEmpty() || ! $fcm->configured()) {
            return ['skipped', null, null, ['reason' => $devices->isEmpty() ? 'no_devices' : 'not_configured'], 'fcm'];
        }
        $delivered = $removed = $busy = 0;
        $last = null;
        foreach ($devices as $device) {
            [$title, $body] = $this->textFor($device);
            $res = $fcm->send($device->push_token, $title, $body, ['event' => $log->event, 'deep_link' => (string) $this->deepLink, 'notification_id' => (string) $this->notificationId, 'log_id' => $log->id], $device);
            if ($res['unregistered'] ?? false) {
                $device->update(['disabled_at' => now()]); // stale token
                $removed++;
            }
            if ($res['ok'] ?? false) {
                $delivered++;
            } elseif ($res['retry'] ?? false) {
                $busy++;
                $last = $res;
            }
        }
        if ($delivered === 0 && $busy > 0) {
            throw new RuntimeException('FCM unavailable: HTTP '.($last['status'] ?? 0).' '.($last['error'] ?? '')); // the queue tries again later
        }

        return [$delivered > 0 ? 'sent' : 'failed', $devices->count().' device(s)', null, ['delivered_to' => $delivered, 'removed' => $removed], 'fcm'];
    }

    /** @return array{0: string, 1: string} title and body in the language of the phone's app (the account's language when that one is unknown) */
    private function textFor(UserDevice $device): array
    {
        $text = $this->texts[substr((string) $device->locale, 0, 2)] ?? null;

        return $text ? [$text['subject'], $text['body']] : [$this->subject, $this->body];
    }
}
