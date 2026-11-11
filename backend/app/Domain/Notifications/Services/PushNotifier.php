<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Fulfillment\Events\DispatchUpdated;
use App\Domain\Identity\Models\UserDevice;
use App\Domain\Orders\Events\OrderUpdated;
use Illuminate\Support\Facades\App;

/**
 * Real-time push for the three apps, sent from the same events that drive the live sockets.
 *  - customer: a data message "order_progress" (step 0..3 of 4) that the app turns into a live progress notification
 *    (iOS gets a normal alert) — updated in place for the same order, so the shade shows one card per order;
 *  - driver: "new delivery" when a job is assigned; branch employees: "ready, needs a driver" and failed deliveries.
 * Never throws into the order flow: callers wrap it in rescue().
 */
class PushNotifier
{
    public function __construct(private readonly FcmClient $fcm) {}

    public function orderUpdated(OrderUpdated $e): void
    {
        if (! $this->fcm->configured()) {
            return;
        }
        $step = self::step($e->status, $e->deliveryStatus);
        $key = $e->deliveryStatus && in_array($e->deliveryStatus, ['picked_up', 'out_for_delivery', 'delivered', 'failed'], true) ? 'delivery_'.$e->deliveryStatus : 'order_'.$e->status;
        $this->each($e->userId, function (UserDevice $d) use ($e, $step, $key) {
            $title = __('push.customer_title', ['n' => $e->number], $d->locale ?: 'en');
            $body = __("push.$key", ['n' => $e->number], $d->locale ?: 'en');
            if ($body === "push.$key") {
                return;
            }
            $this->fcm->sendProgress($d->push_token, $title, $body, [
                'kind' => 'order_progress', 'event' => 'order.updated', 'order_id' => $e->orderId, 'number' => $e->number, 'step' => $step, 'total' => 4,
                'step_label' => __('push.step_'.$step, [], $d->locale ?: 'en'), 'done' => $step >= 3 ? '1' : '0', 'deep_link' => '/orders/'.$e->orderId,
            ]);
        });
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
    }

    /** 0 placed · 1 preparing · 2 on the way / ready · 3 done — same ladder as the app's order card. */
    public static function step(string $status, ?string $delivery): int
    {
        if ($delivery === 'delivered') {
            return 3;
        }

        return match ($status) {
            'pending_payment', 'confirmed' => 0,
            'processing', 'partially_fulfilled' => 1,
            'out_for_delivery', 'ready_for_pickup' => 2,
            default => 3,
        };
    }

    private function branch(string $branchId, string $key, string $number): void
    {
        BranchEmployee::where('branch_id', $branchId)->where('is_active', true)->pluck('user_id')->unique()
            ->each(fn ($uid) => $this->plain($uid, $key, $number, 'dispatch.updated'));
    }

    private function plain(string $userId, string $key, string $number, string $event): void
    {
        $this->each($userId, fn (UserDevice $d) => $this->fcm->send($d->push_token, __('push.staff_title', [], $d->locale ?: 'en'),
            __($key, ['n' => $number], $d->locale ?: 'en'), ['event' => $event, 'number' => $number]));
    }

    private function each(string $userId, callable $fn): void
    {
        $prev = App::getLocale();
        UserDevice::where('user_id', $userId)->whereNull('disabled_at')->get()->each(function (UserDevice $d) use ($fn) {
            $res = $fn($d);
            if (is_array($res) && ($res['unregistered'] ?? false)) {
                $d->update(['disabled_at' => now()]);
            }
        });
        App::setLocale($prev);
    }
}
