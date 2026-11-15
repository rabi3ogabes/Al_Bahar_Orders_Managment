<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Models\LiveActivityToken;
use App\Domain\Orders\Services\OrderProgress;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Turns the customer's live order card ({@see OrderProgress}) into iPhone Live Activity pushes: the order on the lock screen and in the
 * Dynamic Island, updated by Apple straight from the server (FCM does not carry them reliably, see {@see ApnsClient}).
 *
 *  - `update` for every card the customer's FCM devices get too (a stage change at priority 10, a bar or countdown move at 5);
 *  - `end` when the order is final (delivered, collected, cancelled, failed): the last state is shown and Apple removes the activity
 *    after platform.live_order.keep_after_done_minutes;
 *  - `start` (iOS 17.2+) through the phone's push-to-start token when the order has no running activity, so the activity can appear
 *    while the app is closed. Off until platform.live_order.activity_remote_start is switched on, because a phone that starts the
 *    activity itself (app open) and a server that starts it at the same moment would show it twice.
 *
 * The content is the card's own text in the language each phone reported, plus the numbers the widget draws the bar and the countdown
 * from. It never carries the driver's phone number or the address: a Live Activity is readable on a locked screen.
 * Nothing here throws: one bad token never stops the others and never reaches the order flow.
 */
class LiveActivityPusher
{
    /** The Swift type of the widget's attributes (mobile/ios/LiveOrderWidget/LiveOrderAttributes.swift). */
    public const ATTRIBUTES_TYPE = 'LiveOrderAttributes';

    public function __construct(
        private readonly ApnsClient $apns,
        private readonly ApnsConfig $config,
        private readonly OrderProgress $progress,
    ) {}

    public function configured(): bool
    {
        return $this->config->configured();
    }

    /**
     * The customer's tokens that matter for one order: the running activity of that order and, only when there is none and remote start is
     * on, the phone's push-to-start tokens (an order whose activity the app has already started must not get a second one). Only the
     * customer who placed the order is ever looked at.
     *
     * @return Collection<int, LiveActivityToken>
     */
    public function tokensFor(string $orderId, string $userId): Collection
    {
        $running = LiveActivityToken::where('user_id', $userId)->whereNull('disabled_at')->where('kind', LiveActivityToken::KIND_ACTIVITY)->where('order_id', $orderId)->get();
        if ($running->isNotEmpty() || ! config('platform.live_order.activity_remote_start', false)) {
            return $running;
        }

        return LiveActivityToken::where('user_id', $userId)->whereNull('disabled_at')->where('kind', LiveActivityToken::KIND_START)->get();
    }

    /**
     * Sends the card to every token. [$quiet] marks a move that is not a stage change (bar, window, countdown): it is sent at Apple's
     * low priority, which is not counted against the activity's update budget.
     *
     * @param  Collection<int, LiveActivityToken>  $tokens
     * @param  array<string, array<string, mixed>>  $payloads  the card by language
     * @return int how many tokens Apple accepted the push for
     */
    public function push(string $orderId, Collection $tokens, array $payloads, bool $quiet): int
    {
        $accepted = 0;
        foreach ($tokens as $token) {
            try {
                $payload = $payloads[$this->progress->locale($token->locale)] ?? reset($payloads);
                $accepted += is_array($payload) && $this->pushOne($orderId, $token, $payload, $quiet) ? 1 : 0;
            } catch (Throwable $e) {
                report($e); // one bad token must not stop the others
            }
        }

        return $accepted;
    }

    /** @param  array<string, mixed>  $p */
    private function pushOne(string $orderId, LiveActivityToken $token, array $p, bool $quiet): bool
    {
        $done = ($p['done'] ?? '0') === '1';
        $now = now()->getTimestamp();
        $base = ['timestamp' => $this->timestamp($p, $now), 'content-state' => $this->contentState($p, $this->progress->locale($token->locale))];
        $priority = 10;
        $startKey = null;

        if ($token->kind === LiveActivityToken::KIND_START) {
            // Once per order and phone, and only for an order that is still going.
            $startKey = 'live_activity:start:'.$orderId.':'.$token->id;
            if ($done || ! Cache::add($startKey, 1, now()->addHours(12))) {
                return false;
            }
            $aps = $base + [
                'event' => 'start',
                'attributes-type' => self::ATTRIBUTES_TYPE,
                'attributes' => $this->attributes($p),
                'alert' => ['title' => (string) ($p['title'] ?? ''), 'body' => (string) ($p['body'] ?? ''), 'sound' => 'default'],
                'input-push-token' => 1, // asks the phone to report the new activity's own token
                'stale-date' => $now + $this->staleSeconds(),
            ];
        } elseif ($done) {
            $aps = $base + ['event' => 'end', 'dismissal-date' => $now + max(0, (int) config('platform.live_order.keep_after_done_minutes', 30)) * 60];
        } else {
            $priority = $quiet ? 5 : 10;
            $aps = $base + ['event' => 'update', 'stale-date' => $now + $this->staleSeconds()];
        }

        $res = $this->apns->sendLiveActivity($token->token, $token->environment, $aps, $priority, $now + (int) config('platform.live_order.activity_ttl_seconds', 3600));

        if ($res['unregistered']) {
            $token->update(['disabled_at' => now()]);
        } elseif ($res['ok']) {
            $token->update(['last_pushed_at' => now()] + ($token->kind === LiveActivityToken::KIND_ACTIVITY && $done ? ['disabled_at' => now()] : []));
        } elseif ($startKey !== null) {
            Cache::forget($startKey); // the start never reached the phone: the next card may try again
        }

        return $res['ok'];
    }

    /**
     * What the widget draws, in the keys of its Swift `ContentState`. Times are Unix seconds; a value the card does not have is left
     * out (the Swift side reads them as optionals), never sent as null or as an empty string.
     *
     * @param  array<string, mixed>  $p  one OrderProgress payload
     * @return array<string, mixed>
     */
    public function contentState(array $p, string $lang): array
    {
        $at = fn (mixed $iso) => is_string($iso) && ($t = strtotime($iso)) !== false ? $t : null;
        $state = [
            'phase' => (string) $p['phase'],
            'detail' => (string) ($p['detail'] ?? $p['phase']),
            'step' => (int) $p['step'],
            'total' => (int) ($p['total'] ?? OrderProgress::TOTAL),
            'sub' => isset($p['sub']) ? round((float) $p['sub'], 2) : null,
            'title' => (string) $p['title'],
            'body' => (string) $p['body'],
            'step_label' => (string) ($p['step_label'] ?? ''),
            'eta_label' => $p['eta_label'] ?? null,
            'eta_from' => $at($p['eta_from'] ?? null),
            'eta_to' => $at($p['eta_to'] ?? null),
            'eta_at' => $at($p['eta_at'] ?? null),
            'driver_first_name' => $p['driver_name'] ?? null,
            'done' => ($p['done'] ?? '0') === '1',
            'lang' => $lang,
        ];

        return array_filter($state, fn (mixed $v) => $v !== null);
    }

    /**
     * The widget's static attributes, needed only to START an activity remotely: the order and the live theme's colours.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, string>
     */
    private function attributes(array $p): array
    {
        $brand = (array) ($p['brand'] ?? []);

        return [
            'order_id' => (string) $p['order_id'],
            'number' => (string) $p['number'],
            'type' => (string) $p['type'],
            'brand_primary' => (string) ($brand['primary'] ?? '#8A1538'),
            'brand_accent' => (string) ($brand['accent'] ?? '#B3254F'),
        ];
    }

    /** The card's own clock (`seq`, milliseconds) in seconds: Apple applies updates in the order of this number and drops an older one. */
    private function timestamp(array $p, int $fallback): int
    {
        $ms = (int) ($p['seq'] ?? 0);

        return $ms > 0 ? intdiv($ms, 1000) : $fallback;
    }

    private function staleSeconds(): int
    {
        return max(1, (int) config('platform.live_order.activity_stale_minutes', 30)) * 60;
    }
}
