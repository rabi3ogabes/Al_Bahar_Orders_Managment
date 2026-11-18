<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserDevice;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Services\PersonalCouponIssuer;
use App\Domain\Messaging\Models\CustomerMessage;
use App\Domain\Messaging\Models\MessageBatch;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Services\FcmClient;
use App\Domain\Notifications\Services\N8nClient;
use App\Domain\Notifications\Services\WhatsAppClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends the queued messages of one customer in one batch. Safe to run twice (a lock per customer, and only rows still `queued` are touched),
 * checks consent and the cooldown again at the moment of sending, issues one personal coupon shared by the customer's channels, and records
 * a result on every row. A notification is the in-app inbox entry plus a push to each of the customer's phones; WhatsApp goes to n8n.
 */
class MessageDelivery
{
    public function __construct(
        private readonly Eligibility $eligibility,
        private readonly MessageVariables $variables,
        private readonly CustomerMessaging $messaging,
        private readonly FcmClient $fcm,
        private readonly N8nClient $n8n,
        private readonly WhatsAppClient $whatsapp,
        private readonly PersonalCouponIssuer $coupons,
    ) {}

    /** @return bool false when another worker is already on this customer (the job tries again later) */
    public function deliver(string $batchId, string $userId): bool
    {
        $lock = Cache::lock('customer-message:'.$batchId.':'.$userId, 120);
        if (! $lock->get()) {
            return false;
        }
        try {
            $this->process($batchId, $userId);
        } finally {
            $lock->release();
        }

        return true;
    }

    /** The job gave up: nothing is left "queued" forever, the history says it failed. */
    public function failRemaining(string $batchId, string $userId, string $reason): void
    {
        CustomerMessage::where('batch_id', $batchId)->where('user_id', $userId)->where('status', 'queued')->update(['status' => 'failed', 'reason' => $reason, 'updated_at' => now()]);
        MessageBatch::find($batchId)?->refreshCounts();
    }

    private function process(string $batchId, string $userId): void
    {
        $batch = MessageBatch::find($batchId);
        if (! $batch || $batch->status !== 'sending') {
            return; // cancelled, or still waiting for its schedule
        }
        $rows = CustomerMessage::where('batch_id', $batchId)->where('user_id', $userId)->where('status', 'queued')->get();
        if ($rows->isNotEmpty()) {
            $user = User::find($userId);
            if (! $user) {
                $rows->each(fn (CustomerMessage $r) => $this->finish($r, 'skipped', 'inactive_user'));
            } else {
                $this->sendTo($batch, $user, $rows);
            }
        }
        $batch->refresh()->refreshCounts();
    }

    /** @param Collection<int, CustomerMessage> $rows */
    private function sendTo(MessageBatch $batch, User $user, $rows): void
    {
        $limits = $this->messaging->limits();
        $eval = $this->eligibility->evaluate(collect([$user]), $rows->pluck('channel')->unique()->values()->all(), $batch->kind, $limits['cooldown_hours'], $batch->id,
            (bool) ($batch->audience['override_cooldown'] ?? false))[(string) $user->id] ?? [];
        $ready = $rows->filter(function (CustomerMessage $row) use ($eval) {
            $reason = array_key_exists($row->channel, $eval) ? $eval[$row->channel] : 'opted_out'; // null = may be sent
            if ($reason) {
                $this->finish($row, 'skipped', $reason);
            }

            return $reason === null;
        });
        if ($ready->isEmpty()) {
            return;
        }
        $content = $batch->content;
        try {
            $coupon = $this->couponFor($batch, $user, $content['coupon'] ?? []);
        } catch (Throwable $e) {
            report($e);
            $ready->each(fn (CustomerMessage $r) => $this->finish($r, 'failed', 'coupon_failed'));

            return;
        }
        $locale = $user->preferredLocale();
        foreach ($ready as $row) {
            try {
                $row->update(['coupon_code' => $coupon?->code, 'locale' => $locale]);
                match ($row->channel) {
                    'notification' => $this->sendNotification($row, $batch, $user, $content, $locale, $coupon),
                    'whatsapp' => $this->sendWhatsApp($row, $batch, $user, $content, $locale, $coupon),
                    default => $this->finish($row, 'skipped', 'opted_out'),
                };
            } catch (Throwable $e) {
                report($e);
                $this->finish($row, 'failed', 'send_error');
            }
        }
    }

    /** @param array<string, mixed> $content */
    private function sendNotification(CustomerMessage $row, MessageBatch $batch, User $user, array $content, string $locale, ?Coupon $coupon): void
    {
        $prefs = $this->eligibility->preferences([(string) $user->id]);
        $inApp = $this->eligibility->allowed($prefs, (string) $user->id, 'in_app');
        $push = $this->eligibility->allowed($prefs, (string) $user->id, 'push');
        [$title, $body] = $this->texts($content, $locale, $user, $coupon);
        $link = $content['deep_link'] ?: ($batch->kind === 'reminder' ? 'albahar://cart' : 'albahar://home');
        // devices = phones tried, pushed = phones Google accepted it for, silent = of those, phones that said notifications are off or never asked
        // (Android 13+ hides the push until the person allows it, so "pushed" alone is not "seen"). Unknown (null) is not counted as silent.
        // rejected = phones Google refused (with the first error word in `error`). push_tried tells "no phone" from "no push attempted", and
        // push_skip says why none was attempted: the customer switched push off, or push is not set up. The history and the result window use them.
        $pushConfigured = $this->fcm->configured();
        $meta = ['in_app' => false, 'devices' => 0, 'pushed' => 0, 'silent' => 0, 'rejected' => 0, 'push_tried' => $push && $pushConfigured];
        if (! $push) {
            $meta['push_skip'] = 'customer';
        } elseif (! $pushConfigured) {
            $meta['push_skip'] = 'not_set_up';
        }
        $inboxId = null;

        if ($inApp) {
            $inboxId = Notification::create(['user_id' => $user->id, 'event' => 'staff.message', 'category' => 'marketing', 'title' => $title, 'body' => $body,
                'data' => array_filter(['deep_link' => $link, 'coupon' => $coupon?->code])])->id;
            $meta['in_app'] = true;
        }
        if ($push && $pushConfigured) {
            foreach ($user->devices()->whereNull('disabled_at')->get() as $device) {
                $meta['devices']++;
                [$t, $b] = $this->texts($content, $device->locale ?: $locale, $user, $coupon); // the app language on that phone
                // `notification_id`: opening the push marks the same message read in the customer's inbox.
                $res = $this->pushOnce($t, $b, array_filter(['event' => 'staff.message', 'deep_link' => $link, 'coupon' => $coupon?->code, 'delivery_id' => $row->id, 'notification_id' => $inboxId]), $device);
                if ($res['unregistered'] ?? false) {
                    $device->update(['disabled_at' => now()]);
                }
                if ($res['ok'] ?? false) {
                    $meta['pushed']++;
                    $meta['silent'] += in_array($device->getAttribute('notification_permission'), ['denied', 'not_asked'], true) ? 1 : 0;
                } else {
                    $meta['rejected']++;
                    $meta['error'] ??= Str::limit((string) ($res['code'] ?? $res['error'] ?? 'HTTP '.($res['status'] ?? 0)), 40, '');
                }
            }
        }
        $row->update(['title' => $title, 'body' => $body, 'meta' => $meta]);
        if ($meta['in_app'] || $meta['pushed'] > 0) {
            $this->finish($row, 'sent');
        } elseif ($meta['devices'] > 0) {
            $this->finish($row, 'failed', 'push_rejected');
        } else {
            $this->finish($row, 'skipped', ! $push ? 'opted_out' : (! $pushConfigured ? 'push_not_configured' : 'no_devices'));
        }
    }

    /**
     * One push to one phone. Google being busy or unreachable (`retry`) gets a second try a moment later, so a hiccup does not leave the customer
     * with the inbox message only; the app shows a repeated push once (it carries the same inbox id).
     *
     * @param  array<string, mixed>  $data
     * @return array{ok: bool, unregistered: bool, retry: bool, status: int, error: ?string, code?: ?string, detail?: ?string}
     */
    private function pushOnce(string $title, string $body, array $data, UserDevice $device): array
    {
        $res = $this->fcm->send($device->push_token, $title, $body, $data, $device);
        if (! $res['ok'] && $res['retry']) {
            Sleep::for(400)->milliseconds();
            $res = $this->fcm->send($device->push_token, $title, $body, $data, $device);
        }

        return $res;
    }

    /** @param array<string, mixed> $content */
    private function sendWhatsApp(CustomerMessage $row, MessageBatch $batch, User $user, array $content, string $locale, ?Coupon $coupon): void
    {
        $vars = $this->variables->for($user, $locale, $coupon?->code, $coupon?->ends_at);
        $ar = $locale === 'ar';
        $own = $ar ? $content['whatsapp_ar'] : $content['whatsapp_en'];
        $text = $this->variables->fill($own !== '' ? $own : (($ar ? $content['title_ar'] : $content['title_en'])."\n".($ar ? $content['body_ar'] : $content['body_en'])), $vars);
        $row->update(['body' => $text]);

        if ($this->n8n->configured()) {
            $res = $this->n8n->send([
                'event' => 'direct.whatsapp', 'delivery_id' => $row->id, 'batch_id' => $batch->id, 'template' => $batch->template_name, 'kind' => $batch->kind,
                'locale' => $locale, 'to' => $user->phone, 'customer' => ['id' => $user->id, 'name' => $vars['name']], 'message' => $text,
                'coupon' => $coupon ? ['code' => $coupon->code, 'expires' => $vars['expires']] : null,
                'callback_url' => url('/api/v1/integrations/n8n/delivery'), 'sent_at' => now()->toIso8601String(),
            ]);
            if ($res['ok']) {
                $row->update(['external_id' => $res['id'] ?? null]);
                $this->finish($row, 'sent');
            } else {
                $this->finish($row, 'failed', 'n8n_http_'.($res['status'] ?? 0));
            }

            return;
        }
        if ($this->whatsapp->configured()) {
            $r = $this->whatsapp->sendText((string) $user->phone, $text);
            $row->update(['external_id' => $r['id'] ?? null]);
            $this->finish($row, 'sent');

            return;
        }
        $this->finish($row, 'skipped', 'whatsapp_not_configured');
    }

    /**
     * One personal one-time coupon per customer per send, shared by the customer's channels and reused if the job runs again.
     *
     * @param  array<string, mixed>  $settings
     */
    private function couponFor(MessageBatch $batch, User $user, array $settings): ?Coupon
    {
        if (! ($settings['enabled'] ?? false)) {
            return null;
        }
        $existing = CustomerMessage::where('batch_id', $batch->id)->where('user_id', $user->id)->whereNotNull('coupon_code')->value('coupon_code');
        if ($existing) {
            return Coupon::where('code', $existing)->first();
        }

        return $this->coupons->issue((string) $settings['promotion_id'], $user, (string) ($settings['prefix'] ?? 'BAHAR'), (int) ($settings['valid_hours'] ?? 72));
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array{0: string, 1: string}
     */
    private function texts(array $content, string $locale, User $user, ?Coupon $coupon): array
    {
        $ar = $locale === 'ar';
        $vars = $this->variables->for($user, $ar ? 'ar' : 'en', $coupon?->code, $coupon?->ends_at);

        return [$this->variables->fill($ar ? $content['title_ar'] : $content['title_en'], $vars), $this->variables->fill($ar ? $content['body_ar'] : $content['body_en'], $vars)];
    }

    private function finish(CustomerMessage $row, string $status, ?string $reason = null): void
    {
        $row->update(['status' => $status, 'reason' => $reason ? Str::limit($reason, 45, '') : null, 'sent_at' => $status === 'sent' ? now() : $row->sent_at]);
    }
}
