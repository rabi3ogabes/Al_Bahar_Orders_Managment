<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Fulfillment\Events\DispatchUpdated;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserDevice;
use App\Domain\Notifications\Models\LiveActivityToken;
use App\Domain\Orders\Events\OrderUpdated;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Services\OrderProgress;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Real-time push for the three apps, sent from the same events that drive the live sockets.
 *  - customer: the live order card ("order_progress", see OrderProgress): sent whenever the order's state really changes
 *    or its arrival window moves by 5+ minutes, in each device's language, and updated in place per order. An iPhone that
 *    runs a Live Activity for the order (lock screen, Dynamic Island) gets the same card straight from Apple's servers too
 *    (see LiveActivityPusher), whether or not Firebase is set up;
 *  - driver: "new delivery" when a job is assigned; branch employees: "ready, needs a driver" and failed deliveries.
 * Never throws into the order flow: the customer card is wrapped here, the driver/staff pushes by their callers' rescue().
 */
class PushNotifier
{
    /** Cache key of what was last pushed for an order: the state fingerprint and the window end. */
    private const LAST = 'order_progress:last:';

    public function __construct(
        private readonly FcmClient $fcm,
        private readonly OrderProgress $progress,
        private readonly LiveActivityPusher $activities,
    ) {}

    public function orderUpdated(OrderUpdated $e): void
    {
        $this->refreshOrder($e->orderId);
    }

    public function dispatchUpdated(DispatchUpdated $e): void
    {
        // Driver and staff pushes are Firebase's; the customer's card below also feeds the iPhone Live Activity, which needs no Firebase.
        // Each of these alerts has a switch in the notification controls (Content > Notification controls).
        if ($this->fcm->configured()) {
            $rules = app(NotificationRules::class);
            if ($e->kind === 'delivery' && $e->status === 'assigned' && $e->driverId && $rules->allows('staff.driver_assigned', 'push')) {
                $this->plain($e->driverId, 'push.driver_new_job', $e->orderNumber, 'dispatch.updated');
            }
            if ($e->kind === 'delivery' && $e->status === 'failed' && $rules->allows('staff.delivery_failed', 'push')) {
                $this->branch($e->branchId, 'push.staff_failed', $e->orderNumber);
            }
            if ($e->kind === 'order' && $e->status === 'ready' && $rules->allows('staff.order_ready', 'push')) {
                $this->branch($e->branchId, 'push.staff_ready', $e->orderNumber);
            }
        }
        if ($e->kind === 'order' && $e->fulfillmentId !== null && Str::isUuid($e->fulfillmentId)) {
            // A branch part moved (accepted, preparing, ready) without necessarily changing the order's status: the customer's card follows.
            $orderId = OrderFulfillment::whereKey($e->fulfillmentId)->value('order_id');
            if ($orderId !== null) {
                $this->refreshOrder($orderId);
            }
        }
    }

    /**
     * Brings the customer's live order card up to date. Safe to call as often as you like: it sends only when the card's
     * state changed since the last push or its arrival window moved by platform.live_order.shift_minutes or more, and
     * never for an order that finished more than 30 minutes ago. Does not throw.
     */
    public function refreshOrder(string $orderId): void
    {
        if (! Str::isUuid($orderId)) {
            return;
        }
        $fcm = $this->fcm->configured();
        $live = $this->activities->configured();
        if (! $fcm && ! $live) {
            return;
        }
        rescue(fn () => $this->pushProgress($orderId, $fcm, $live), report: true);
    }

    /** @param  bool  $fcm  Firebase may send the card to the customer's phones and browsers; @param  bool  $live  Apple may update their Live Activities */
    private function pushProgress(string $orderId, bool $fcm, bool $live): void
    {
        $order = Order::find($orderId);
        if ($order === null || $order->user_id === null) {
            return;
        }
        // The card is an "order updates" push: a customer who switched those off in the app never gets it, like the classic push.
        $customer = User::find($order->user_id);
        if ($customer === null || ! $customer->isActive() || ! app(NotificationService::class)->allowed($customer, 'push', 'transactional')) {
            return;
        }
        /** @var Collection<int, UserDevice> $devices */
        $devices = $fcm ? UserDevice::where('user_id', $order->user_id)->whereNull('disabled_at')->get() : new Collection;
        /** @var Collection<int, LiveActivityToken> $tokens */
        $tokens = $live ? $this->activities->tokensFor($order->id, $order->user_id) : new Collection;
        if ($devices->isEmpty() && $tokens->isEmpty()) {
            return;
        }
        $locales = [...$devices->map(fn (UserDevice $d) => (string) $d->locale)->all(), ...$tokens->map(fn (LiveActivityToken $t) => (string) $t->locale)->all()];
        $built = $this->progress->build($order, $locales);
        if ($built === null || $built['expired']) {
            return;
        }
        // The notification controls can switch a step of the card off together with its message (order accepted, ready, ...): that step is
        // skipped, so the card stays on the step before it until the next step that is on. Nothing is recorded, so a later step still sends.
        $step = NotificationCatalog::cardEvent((string) (reset($built['payloads'])['phase'] ?? ''));
        if ($step !== null && ! app(NotificationRules::class)->allows($step, NotificationRules::LIVE_CARD)) {
            return;
        }
        try {
            // Two events for one change (order + dispatch) may be handled at the same moment: the second finds the first's record and stops.
            Cache::lock('order_progress:push:'.$orderId, 20)->block(5, fn () => $this->sendIfChanged($orderId, $devices, $tokens, $built['payloads']));
        } catch (LockTimeoutException) {
            // Another worker is sending this order's update right now.
        }
    }

    /**
     * @param  Collection<int, UserDevice>  $devices  the phones and browsers Firebase sends the card to
     * @param  Collection<int, LiveActivityToken>  $tokens  the customer's Live Activities for this order (Apple sends these)
     * @param  array<string, array<string, mixed>>  $payloads  by language
     */
    private function sendIfChanged(string $orderId, Collection $devices, Collection $tokens, array $payloads): void
    {
        $first = reset($payloads);
        $state = md5((string) json_encode([$first['phase'], $first['detail'], $first['step'], $first['driver_name'], $first['done']]));
        $etaTo = $first['eta_to'] !== null ? strtotime($first['eta_to']) : null;
        $etaAt = isset($first['eta_at']) ? strtotime($first['eta_at']) : null;
        $steps = (int) config('platform.live_order.progress_steps', 20);       // 0 turns the bar pushes off
        $countdownShift = (int) config('platform.live_order.countdown_shift_seconds', 60); // 0 turns the countdown pushes off
        $fill = $steps > 0 && isset($first['sub']) ? (int) round((float) $first['sub'] * $steps) : null;
        $last = Cache::get(self::LAST.$orderId);

        $changed = ! is_array($last) || $last['state'] !== $state;
        $shifted = ! $changed && $etaTo !== null && ($last['eta_to'] ?? null) !== null
            && abs($etaTo - $last['eta_to']) >= (int) config('platform.live_order.shift_minutes', 5) * 60;
        // Between stages the card keeps moving: the bar is redrawn when its fill crosses a step, and the countdown is reset
        // when the driver's arrival time shifts, never more often than min_push_seconds per order.
        $moved = false;
        if (! $changed && ! $shifted) {
            $recent = now()->getTimestamp() - (int) ($last['at'] ?? 0) < (int) config('platform.live_order.min_push_seconds', 15);
            $barMoved = $fill !== null && isset($last['fill']) && $fill !== $last['fill'];
            $countdownMoved = $countdownShift > 0 && $etaAt !== null && isset($last['eta_at']) && abs($etaAt - $last['eta_at']) >= $countdownShift;
            $moved = ! $recent && ($barMoved || $countdownMoved);
        }
        if (! $changed && ! $shifted && ! $moved) {
            return;
        }
        $silent = $shifted || $moved;

        $delivered = 0;
        foreach ($devices as $device) {
            $payload = $payloads[$this->progress->locale($device->locale)];
            try {
                $res = $this->fcm->sendProgress($device->push_token, $payload['title'], $payload['body'], $silent ? $payload + ['quiet' => '1'] : $payload, $device);
            } catch (Throwable $e) {
                report($e); // one bad device must not stop the others

                continue;
            }
            $this->retireIfGone($device, $res);
            $delivered += ($res['ok'] ?? false) ? 1 : 0;
        }
        // The Live Activity: a stage change at Apple's high priority, a bar or countdown move at the low one; a finished order ends it.
        if ($tokens->isNotEmpty()) {
            $delivered += $this->activities->push($orderId, $tokens, $payloads, $silent);
        }
        if ($delivered > 0) {
            Cache::put(self::LAST.$orderId, ['state' => $state, 'eta_to' => $etaTo, 'eta_at' => $etaAt, 'fill' => $fill, 'at' => now()->getTimestamp()], now()->addDay());
        }
    }

    /**
     * A branch alert (a new order, a reminder that one waits) to ONE staff member's phones and browsers, each in the language of that device.
     * `$title` and `$body` are built per language by the caller (an array locale => text), so no customer data is added here, and `$data` is what
     * the app receives to open the right screen. Whether the alert may be sent at all (the admin's notification controls) is the caller's check.
     * Returns how many devices accepted it. Does not throw: one bad device must not stop the others.
     *
     * @param  array<string, string>  $title
     * @param  array<string, string>  $body
     * @param  array<string, mixed>  $data
     */
    public function staffAlert(string $userId, array $title, array $body, array $data): int
    {
        if (! $this->fcm->configured()) {
            return 0;
        }
        $sent = 0;
        $this->each($userId, function (UserDevice $d) use ($title, $body, $data, &$sent) {
            $lang = substr((string) $d->locale, 0, 2);
            $locale = isset($title[$lang]) ? $lang : (string) array_key_first($title);
            $res = $this->fcm->send($d->push_token, $title[$locale], $body[$locale] ?? '', $data, $d);
            $sent += ($res['ok'] ?? false) ? 1 : 0;

            return $res;
        });

        return $sent;
    }

    private function branch(string $branchId, string $key, string $number): void
    {
        BranchEmployee::where('branch_id', $branchId)->where('is_active', true)->pluck('user_id')->unique()
            ->each(fn ($uid) => $this->plain($uid, $key, $number, 'dispatch.updated'));
    }

    private function plain(string $userId, string $key, string $number, string $event): void
    {
        $this->each($userId, fn (UserDevice $d) => $this->fcm->send($d->push_token, __('push.staff_title', [], $d->locale ?: 'en'),
            __($key, ['n' => $number], $d->locale ?: 'en'), ['event' => $event, 'number' => $number], $d));
    }

    private function each(string $userId, callable $fn): void
    {
        $prev = App::getLocale();
        UserDevice::where('user_id', $userId)->whereNull('disabled_at')->get()->each(function (UserDevice $d) use ($fn) {
            try {
                $this->retireIfGone($d, $fn($d));
            } catch (Throwable $e) {
                report($e); // one bad device must not stop the others
            }
        });
        App::setLocale($prev);
    }

    /** A token FCM no longer knows (app uninstalled) stops receiving pushes. */
    private function retireIfGone(UserDevice $d, mixed $res): void
    {
        if (is_array($res) && ($res['unregistered'] ?? false)) {
            $d->update(['disabled_at' => now()]);
        }
    }
}
