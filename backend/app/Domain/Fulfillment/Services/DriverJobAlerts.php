<?php

namespace App\Domain\Fulfillment\Services;

use App\Domain\Identity\Models\UserDevice;
use App\Domain\Notifications\Services\FcmClient;
use Throwable;

/**
 * The two pushes a driver must get at once, or he drives for nothing: "this delivery was cancelled" and "this job was given to
 * another driver". The live socket (DriverJobUpdated) already carries the new state; this is the same message as a phone notification
 * for a driver whose app is in the background, in the language of each of his phones. Sent after the commit, never throws into the
 * order flow, and does nothing while Firebase is not set up (PushNotifier's own driver pushes behave the same).
 */
class DriverJobAlerts
{
    public function __construct(private readonly FcmClient $fcm) {}

    public function cancelled(string $driverId, string $orderNumber): void
    {
        $this->send($driverId, $orderNumber, 'ops_integrity.push.job_cancelled', 'cancelled');
    }

    public function removed(string $driverId, string $orderNumber): void
    {
        $this->send($driverId, $orderNumber, 'ops_integrity.push.job_removed', 'removed');
    }

    private function send(string $driverId, string $orderNumber, string $key, string $job): void
    {
        if (! $this->fcm->configured()) {
            return;
        }
        foreach (UserDevice::where('user_id', $driverId)->whereNull('disabled_at')->get() as $device) {
            $locale = $device->locale ?: 'en';
            try {
                $res = $this->fcm->send($device->push_token, __('push.staff_title', [], $locale), __($key, ['n' => $orderNumber], $locale),
                    ['event' => 'dispatch.updated', 'number' => $orderNumber, 'job' => $job], $device);
                if (is_array($res) && ($res['unregistered'] ?? false)) {
                    $device->update(['disabled_at' => now()]); // the app was uninstalled: stop sending to it
                }
            } catch (Throwable $e) {
                report($e); // one bad phone must not stop the others
            }
        }
    }
}
