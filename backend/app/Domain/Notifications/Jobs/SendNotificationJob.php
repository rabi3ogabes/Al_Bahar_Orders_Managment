<?php

namespace App\Domain\Notifications\Jobs;

use App\Domain\Identity\Models\User;
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
use Throwable;

/** Delivers one queued message on one channel, with exponential backoff and a redacted provider log. */
class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $logId, public readonly string $subject, public readonly string $body, public readonly ?string $deepLink = null, public readonly ?string $notificationId = null)
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

    private function push(FcmClient $fcm, ?User $user, NotificationLog $log): array
    {
        $devices = $user?->devices()->whereNull('disabled_at')->get() ?? collect();
        if ($devices->isEmpty() || ! $fcm->configured()) {
            return ['skipped', null, null, ['reason' => $devices->isEmpty() ? 'no_devices' : 'not_configured'], 'fcm'];
        }
        $ok = 0;
        foreach ($devices as $device) {
            $res = $fcm->send($device->push_token, $this->subject, $this->body, ['event' => $log->event, 'deep_link' => (string) $this->deepLink, 'notification_id' => (string) $this->notificationId, 'log_id' => $log->id], $device);
            if ($res['unregistered'] ?? false) {
                $device->update(['disabled_at' => now()]); // stale token
            }
            $ok += ($res['ok'] ?? false) ? 1 : 0;
        }

        return [$ok > 0 ? 'sent' : 'failed', $devices->count().' device(s)', null, ['delivered_to' => $ok], 'fcm'];
    }
}
