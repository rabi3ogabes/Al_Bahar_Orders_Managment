<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Identity\Models\UserDevice;
use App\Domain\Notifications\Models\NotificationLog;
use App\Support\BusinessTime;
use App\Support\Phone;
use Illuminate\Support\Collection;
use Throwable;

/**
 * A real test notification to one registered phone or browser, with FCM's answer put in plain words. The dashboard's "Send a test" and
 * `php artisan push:test` both use it, so they say the same thing. For the customer app on Android it can also send a sample of the live
 * order card (the data-only `order_progress` message the app draws itself).
 */
class PushTester
{
    /** The order id of the sample card: a fixed one, so a second test replaces the first card instead of stacking. */
    public const SAMPLE_ORDER = 'test-order-card';

    public function __construct(private readonly FcmClient $fcm, private readonly FirebaseConfig $config) {}

    /** The device has the live order card (only the customer app on Android draws it). */
    public static function hasCard(UserDevice $device): bool
    {
        return $device->platform === 'android' && $device->app === 'customer';
    }

    /**
     * Sends the test (and the sample card when asked and the device can show it). Never throws.
     *
     * @return array{ok: bool, outcome: string, status: int, code: ?string, detail: ?string, explain: string, card: ?array{ok: bool, status: int, code: ?string}}
     */
    public function send(UserDevice $device, bool $withCard = false): array
    {
        if (! $this->config->configured()) {
            return ['ok' => false, 'outcome' => 'failed', 'status' => 0, 'code' => null, 'detail' => null, 'explain' => 'not_ready', 'card' => null];
        }
        $locale = $device->locale === 'ar' ? 'ar' : 'en';
        $title = __('push_health.test.title', [], $locale);
        $body = __('push_health.test.body', ['time' => BusinessTime::display(now())], $locale);
        try {
            $res = $this->fcm->send($device->push_token, $title, $body, ['event' => 'push.test', 'kind' => 'test', 'deep_link' => '/notifications'], $device);
        } catch (Throwable $e) {
            report($e);
            $res = ['ok' => false, 'unregistered' => false, 'retry' => true, 'status' => 0, 'error' => 'REQUEST_FAILED'];
        }
        if ($res['unregistered'] ?? false) {
            $device->update(['disabled_at' => now()]); // FCM says this token can never work again
        }
        $card = null;
        if ($withCard && self::hasCard($device) && ($res['ok'] ?? false)) {
            $card = $this->sendCard($device, $locale);
        }
        $outcome = ($res['ok'] ?? false) ? 'sent' : (($res['unregistered'] ?? false) ? 'removed' : 'failed');

        return [
            'ok' => $outcome === 'sent', 'outcome' => $outcome, 'status' => (int) ($res['status'] ?? 0),
            'code' => $res['code'] ?? $res['error'] ?? null, 'detail' => $res['detail'] ?? null, 'explain' => self::explain($res), 'card' => $card,
        ];
    }

    /**
     * Which sentence in `push_health.fcm.*` says what FCM answered.
     *
     * @param  array{ok?: bool, unregistered?: bool, status?: int, error?: ?string, code?: ?string}  $res
     */
    public static function explain(array $res): string
    {
        if ($res['ok'] ?? false) {
            return 'ok';
        }
        $code = (string) ($res['code'] ?? '');
        $error = (string) ($res['error'] ?? '');
        $status = (int) ($res['status'] ?? 0);

        return match (true) {
            $code === 'SENDER_ID_MISMATCH' => 'sender_mismatch',
            $code === 'THIRD_PARTY_AUTH_ERROR' => 'apns_key',
            $res['unregistered'] ?? false, $code === 'UNREGISTERED' => 'unregistered',
            $code === 'QUOTA_EXCEEDED', $status === 429, $error === 'RESOURCE_EXHAUSTED' => 'rate_limited',
            $error === 'UNAUTHENTICATED' || $status === 401 => 'unauthenticated',
            $error === 'PERMISSION_DENIED' || $status === 403 => 'permission_denied',
            $error === 'NOT_FOUND' || $status === 404 => 'not_found',
            $error === 'INVALID_ARGUMENT' || $status === 400 => 'invalid_argument',
            $status >= 500, in_array($error, ['UNAVAILABLE', 'INTERNAL'], true) => $status === 0 ? 'unreachable' : 'fcm_down',
            default => 'other',
        };
    }

    /**
     * The devices a test can go to: the signed-in person's own first, then the latest phones of anybody, so "test the customer app on this
     * phone" works for a phone that belongs to a customer.
     *
     * @return Collection<int, UserDevice>
     */
    public function candidates(?string $ownerId, int $limit = 15): Collection
    {
        $mine = $ownerId === null ? collect() : UserDevice::where('user_id', $ownerId)->whereNull('disabled_at')->orderByDesc('last_seen_at')->limit($limit)->get();
        $recent = UserDevice::with('user:id,name,phone')->whereNull('disabled_at')->whereIn('platform', ['android', 'ios'])
            ->when($ownerId !== null, fn ($q) => $q->where('user_id', '!=', $ownerId))->orderByDesc('last_seen_at')->limit($limit)->get();

        return $mine->concat($recent)->unique('id')->values();
    }

    /** A line that tells a device apart without a token: app, platform, version, owner (masked phone) and when it was last seen. */
    public static function label(UserDevice $d): string
    {
        $kind = $d->platform === 'web' ? ($d->browser ?: __('push_health.target.web')) : __('push_health.target.'.$d->platform);
        $app = $d->app && $d->platform !== 'web' ? __('push_health.target.app.'.$d->app) : null;
        $owner = $d->relationLoaded('user') && $d->user !== null ? ($d->user->phone ? Phone::mask($d->user->phone) : $d->user->name) : null;
        $seen = $d->last_seen_at ? BusinessTime::display($d->last_seen_at) : null;

        return implode(' · ', array_filter([$app, $kind, $d->app_version, $owner, $seen]));
    }

    /** Writes one line to the notification log so the test shows in the dashboard's "Last delivery" and "Last problem". */
    public function log(?string $userId, int $devices, int $ok, ?string $lastError): void
    {
        NotificationLog::create([
            'user_id' => $userId, 'event' => 'push.test', 'channel' => 'push', 'provider' => 'fcm', 'recipient' => $devices.' device(s)',
            'status' => $ok > 0 ? 'sent' : 'failed', 'attempts' => 1, 'sent_at' => $ok > 0 ? now() : null,
            'error' => $ok > 0 ? null : substr((string) ($lastError ?? 'failed'), 0, 490), 'response' => ['delivered_to' => $ok, 'devices' => $devices],
        ]);
    }

    /** @return array{ok: bool, status: int, code: ?string} */
    private function sendCard(UserDevice $device, string $locale): array
    {
        $payload = self::sampleCard($locale);
        try {
            $res = $this->fcm->sendProgress($device->push_token, $payload['title'], $payload['body'], $payload, $device);
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'status' => 0, 'code' => 'REQUEST_FAILED'];
        }

        return ['ok' => (bool) ($res['ok'] ?? false), 'status' => (int) ($res['status'] ?? 0), 'code' => $res['code'] ?? $res['error'] ?? null];
    }

    /**
     * A believable "preparing" card with no real order behind it. Same keys as OrderProgress::present(); `seq` is now, so the app accepts it.
     *
     * @return array<string, mixed>
     */
    public static function sampleCard(string $locale): array
    {
        $now = now();

        return [
            'kind' => 'order_progress', 'order_id' => self::SAMPLE_ORDER, 'number' => 'TEST', 'type' => 'delivery',
            'step' => 1, 'total' => 4, 'sub' => 0.5, 'phase' => 'preparing', 'detail' => 'preparing',
            'step_label' => __('push_health.test.card_step', [], $locale),
            'title' => __('push_health.test.card_title', [], $locale), 'body' => __('push_health.test.card_body', [], $locale),
            'seq' => (int) round($now->getPreciseTimestamp(3)), 'updated_at' => $now->clone()->utc()->toIso8601ZuluString(),
            'deep_link' => '/orders', 'done' => '0',
        ];
    }
}
