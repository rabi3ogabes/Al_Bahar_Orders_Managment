<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Fulfillment\Events\DispatchUpdated;
use App\Domain\Identity\Models\UserDevice;
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
 *    or its arrival window moves by 5+ minutes, in each device's language, and updated in place per order;
 *  - driver: "new delivery" when a job is assigned; branch employees: "ready, needs a driver" and failed deliveries.
 * Never throws into the order flow: the customer card is wrapped here, the driver/staff pushes by their callers' rescue().
 */
class PushNotifier
{
    /** Cache key of what was last pushed for an order: the state fingerprint and the window end. */
    private const LAST = 'order_progress:last:';

    public function __construct(private readonly FcmClient $fcm, private readonly OrderProgress $progress) {}

    public function orderUpdated(OrderUpdated $e): void
    {
        $this->refreshOrder($e->orderId);
    }

    public function dispatchUpdated(DispatchUpdated $e): void
    {
        if (! $this->fcm->configured()) {
            return;
        }
        if ($e->kind === 'delivery' && $e->status === 'assigned' && $e->driverId) {
            $this->plain($e->driverId, 'push.driver_new_job', $e->orderNumber, 'dispatch.updated');
        }
        if ($e->kind === 'delivery' && $e->status === 'failed') {
            $this->branch($e->branchId, 'push.staff_failed', $e->orderNumber);
        }
        if ($e->kind === 'order' && $e->status === 'ready') {
            $this->branch($e->branchId, 'push.staff_ready', $e->orderNumber);
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
        if (! $this->fcm->configured() || ! Str::isUuid($orderId)) {
            return;
        }
        rescue(fn () => $this->pushProgress($orderId), report: true);
    }

    private function pushProgress(string $orderId): void
    {
        $order = Order::find($orderId);
        if ($order === null || $order->user_id === null) {
            return;
        }
        $devices = UserDevice::where('user_id', $order->user_id)->whereNull('disabled_at')->get();
        if ($devices->isEmpty()) {
            return;
        }
        $built = $this->progress->build($order, $devices->map(fn (UserDevice $d) => (string) $d->locale)->all());
        if ($built === null || $built['expired']) {
            return;
        }
        try {
            // Two events for one change (order + dispatch) may be handled at the same moment: the second finds the first's record and stops.
            Cache::lock('order_progress:push:'.$orderId, 20)->block(5, fn () => $this->sendIfChanged($orderId, $devices, $built['payloads']));
        } catch (LockTimeoutException) {
            // Another worker is sending this order's update right now.
        }
    }

    /**
     * @param  Collection<int, UserDevice>  $devices
     * @param  array<string, array<string, mixed>>  $payloads  by language
     */
    private function sendIfChanged(string $orderId, Collection $devices, array $payloads): void
    {
        $first = reset($payloads);
        $state = md5((string) json_encode([$first['phase'], $first['detail'], $first['step'], $first['driver_name'], $first['done']]));
        $etaTo = $first['eta_to'] !== null ? strtotime($first['eta_to']) : null;
        $last = Cache::get(self::LAST.$orderId);

        $changed = ! is_array($last) || $last['state'] !== $state;
        $shifted = ! $changed && $etaTo !== null && ($last['eta_to'] ?? null) !== null
            && abs($etaTo - $last['eta_to']) >= (int) config('platform.live_order.shift_minutes', 5) * 60;
        if (! $changed && ! $shifted) {
            return;
        }

        $delivered = 0;
        foreach ($devices as $device) {
            $payload = $payloads[$this->progress->locale($device->locale)];
            try {
                $res = $this->fcm->sendProgress($device->push_token, $payload['title'], $payload['body'], $shifted ? $payload + ['quiet' => '1'] : $payload, $device);
            } catch (Throwable $e) {
                report($e); // one bad device must not stop the others

                continue;
            }
            $this->retireIfGone($device, $res);
            $delivered += ($res['ok'] ?? false) ? 1 : 0;
        }
        if ($delivered > 0) {
            Cache::put(self::LAST.$orderId, ['state' => $state, 'eta_to' => $etaTo], now()->addDay());
        }
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
            $this->retireIfGone($d, $fn($d));
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
