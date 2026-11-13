<?php

namespace App\Domain\Marketing\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\Coupon;
use Illuminate\Support\Str;

/** Creates a one-time coupon of a promotion that only one customer can use (journeys, referral rewards). */
class PersonalCouponIssuer
{
    public function issue(string $promotionId, User $user, ?string $prefix = null, int $validHours = 168): Coupon
    {
        do {
            $code = strtoupper($prefix ?: 'BAHAR').'-'.strtoupper(Str::random(6));
        } while (Coupon::where('code', $code)->exists());

        return Coupon::create(['promotion_id' => $promotionId, 'code' => $code, 'usage_limit' => 1, 'per_customer_limit' => 1, 'assigned_user_id' => $user->id,
            'is_active' => true, 'starts_at' => now(), 'ends_at' => now()->addHours(max(1, $validHours))]);
    }
}
