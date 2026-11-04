<?php

namespace App\Domain\Marketing\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserDevice;
use App\Domain\Marketing\Models\Referral;
use App\Domain\Orders\Models\Order;
use App\Domain\Settings\Services\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Referrals with fraud controls: no self-referral, referee must be a brand-new customer, one referrer per referee,
 * no reward when referrer and referee share a device, and the reward is granted only after the referee's first
 * COMPLETED (paid, fulfilled) order — never on sign-up alone.
 */
class ReferralService
{
    public function __construct(private readonly LoyaltyService $loyalty, private readonly Settings $settings) {}

    public function attach(User $referee, string $code, ?string $deviceId = null): ?Referral
    {
        $referrer = User::where('referral_code', strtoupper(trim($code)))->where('type', 'customer')->first();
        if (! $referrer || $referrer->id === $referee->id || Referral::where('referee_id', $referee->id)->exists()) {
            return null;
        }
        $sharedDevice = $deviceId && UserDevice::where('user_id', $referrer->id)->where('device_id', $deviceId)->exists();

        return Referral::create(['referrer_id' => $referrer->id, 'referee_id' => $referee->id, 'code' => $referrer->referral_code,
            'status' => $sharedDevice ? 'rejected' : 'pending', 'rejection_reason' => $sharedDevice ? 'shared_device' : null]);
    }

    public function qualify(Order $order): void
    {
        if (! $order->user_id) {
            return;
        }
        DB::transaction(function () use ($order) {
            $ref = Referral::where('referee_id', $order->user_id)->where('status', 'pending')->lockForUpdate()->first();
            if (! $ref) {
                return;
            }
            $firstCompleted = ! Order::where('user_id', $order->user_id)->whereKeyNot($order->id)->whereIn('status', ['completed', 'partially_cancelled'])->exists();
            if (! $firstCompleted) {
                $ref->update(['status' => 'rejected', 'rejection_reason' => 'not_first_order']);

                return;
            }
            $ref->update(['status' => 'rewarded', 'qualifying_order_id' => $order->id, 'rewarded_at' => now()]);
            $this->loyalty->award(User::find($ref->referrer_id), (int) $this->settings->get('loyalty.referral_referrer_points', 500), 'referral', 'Referral reward', 'referral', $ref->id);
            $this->loyalty->award(User::find($ref->referee_id), (int) $this->settings->get('loyalty.referral_referee_points', 250), 'referral', 'Welcome referral reward', 'referral', $ref->id);
        });
    }

    public function summary(User $user): array
    {
        return [
            'code' => $user->referral_code,
            'share_url' => rtrim(config('app.url'), '/').'/app/invite/'.$user->referral_code,
            'referred_count' => Referral::where('referrer_id', $user->id)->count(),
            'rewarded_count' => Referral::where('referrer_id', $user->id)->where('status', 'rewarded')->count(),
            'referrer_reward_points' => (int) $this->settings->get('loyalty.referral_referrer_points', 500),
            'referee_reward_points' => (int) $this->settings->get('loyalty.referral_referee_points', 250),
        ];
    }
}
