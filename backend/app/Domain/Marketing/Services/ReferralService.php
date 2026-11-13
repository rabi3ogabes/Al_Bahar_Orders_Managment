<?php

namespace App\Domain\Marketing\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserDevice;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Marketing\Models\Referral;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Orders\Models\Order;
use App\Domain\Settings\Services\Settings;
use App\Support\BusinessTime;
use App\Support\DomainException;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Referral programme (switched on by an admin in Marketing → Referrals; off by default).
 *
 * Fraud controls: no self-referral, one referrer per friend, no auto-reward when the two accounts share a device,
 * and a reward only after the friend's FIRST order is completed and still paid for (never on sign-up alone, never
 * for a cancelled or refunded order). Same phone / same address are shown to staff as flags.
 *
 * Rewards per side are either a balance (loyalty points worth the QAR amount, since points are the customer's
 * wallet) or a personal single-use coupon of a promotion. A referral is rewarded at most once.
 */
class ReferralService
{
    public const SIDES = ['referrer', 'friend'];

    public function __construct(
        private readonly LoyaltyService $loyalty,
        private readonly Settings $settings,
        private readonly NotificationService $notify,
        private readonly PersonalCouponIssuer $coupons,
        private readonly AuditLogger $audit,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('referrals.enabled', false);
    }

    public function setting(string $key): mixed
    {
        return $this->settings->get('referrals.'.$key);
    }

    /** @return array{type: string, amount: Money, promotion_id: ?string, prefix: string, valid_hours: int} type: balance|coupon|none */
    public function reward(string $side): array
    {
        $type = (string) $this->setting($side.'_type');
        $amount = Money::of((string) ($this->setting($side.'_amount') ?: '0'));
        $promotionId = $this->setting($side.'_promotion_id') ?: null;
        $type = match (true) {
            $type === 'balance' && $amount->isPositive() => 'balance',
            $type === 'coupon' && $promotionId !== null => 'coupon',
            default => 'none',
        };

        return ['type' => $type, 'amount' => $amount, 'promotion_id' => $promotionId, 'prefix' => (string) ($this->setting($side.'_prefix') ?: 'FRIEND'), 'valid_hours' => (int) ($this->setting($side.'_valid_hours') ?: 168)];
    }

    public function minOrder(): Money
    {
        return Money::of((string) ($this->setting('min_order_amount') ?: '0'));
    }

    public function attach(User $referee, string $code, ?string $deviceId = null): ?Referral
    {
        if (! $this->enabled()) {
            return null;
        }
        $referrer = User::where('referral_code', strtoupper(trim($code)))->where('type', 'customer')->first();
        if (! $referrer || $referrer->id === $referee->id || Referral::where('referee_id', $referee->id)->exists()) {
            return null;
        }
        $sharedDevice = $deviceId && UserDevice::where('user_id', $referrer->id)->where('device_id', $deviceId)->exists();

        return Referral::create(['referrer_id' => $referrer->id, 'referee_id' => $referee->id, 'code' => $referrer->referral_code,
            'status' => $sharedDevice ? 'rejected' : 'pending', 'rejection_reason' => $sharedDevice ? 'shared_device' : null,
            'rejected_at' => $sharedDevice ? now() : null, 'fraud_flags' => $sharedDevice ? ['shared_device'] : []]);
    }

    /** Called when an order completes: rewards the referral of its customer if every rule holds. */
    public function qualify(Order $order): void
    {
        if (! $order->user_id || ! $this->enabled()) {
            return;
        }
        $granted = DB::transaction(function () use ($order): ?array {
            $ref = Referral::where('referee_id', $order->user_id)->where('status', 'pending')->lockForUpdate()->first();
            $order->refresh();
            if (! $ref || ! in_array($order->status->value, ['completed', 'partially_cancelled'], true)) {
                return null;
            }
            $paid = Money::of($order->paid_total);
            $net = ($paid->isPositive() ? $paid : Money::of($order->grand_total))->minus(Money::of($order->refunded_total))->minus(Money::of($order->delivery_fee_total));
            if (! $net->isPositive() || in_array($order->payment_status->value, ['refunded', 'failed', 'cancelled', 'expired'], true)) {
                return null; // refunded or unpaid: nothing to reward (a later order may still qualify)
            }
            if (Order::where('user_id', $order->user_id)->whereKeyNot($order->id)->whereIn('status', ['completed', 'partially_cancelled'])->exists()) {
                return $this->close($ref, 'not_first_order');
            }
            if ($net->lessThan($this->minOrder())) {
                return $this->close($ref, 'below_minimum');
            }
            if (($order->placed_at ?? $order->created_at)->gt($ref->created_at->copy()->addDays(max(1, (int) $this->setting('validity_days'))))) {
                return $this->close($ref, 'expired');
            }
            $referrer = User::whereKey($ref->referrer_id)->lockForUpdate()->first(); // serialises the per-referrer cap
            $referee = User::find($ref->referee_id);
            $cap = (int) $this->setting('max_rewards_per_referrer');
            if ($cap > 0 && Referral::where('referrer_id', $ref->referrer_id)->where('status', 'rewarded')->count() >= $cap) {
                return $this->close($ref, 'referrer_cap_reached');
            }

            $update = ['status' => 'rewarded', 'qualifying_order_id' => $order->id, 'rewarded_at' => now(),
                'fraud_flags' => array_values(array_unique(array_merge($ref->fraud_flags ?? [], $this->fraudFlags($referrer, $referee))))];
            $given = [];
            foreach (self::SIDES as $side) {
                $col = $side === 'friend' ? 'referee' : 'referrer';
                $r = $this->grant($side, $side === 'friend' ? $referee : $referrer, $ref);
                $given[$side] = $r;
                $update += [$col.'_reward_type' => $r['type'], $col.'_reward_points' => $r['points'], $col.'_reward_value' => $r['value'], $col.'_coupon_code' => $r['code']];
            }
            $ref->update($update);
            $this->audit->log('marketing.referral_rewarded', $ref, [], ['referrer' => $ref->referrer_id, 'referee' => $ref->referee_id, 'order' => $order->id], ['referrer_reward' => $given['referrer']['type'], 'friend_reward' => $given['friend']['type']]);

            return ['ref' => $ref, 'given' => $given, 'referrer' => $referrer, 'referee' => $referee];
        });

        if ($granted) {
            $this->announce($granted['ref'], $granted['given'], $granted['referrer'], $granted['referee']);
        }
    }

    /** Staff decision: stop a pending referral from ever being rewarded. */
    public function reject(Referral $ref, User $staff, string $reason): void
    {
        DB::transaction(function () use ($ref, $staff, $reason) {
            $locked = Referral::whereKey($ref->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw DomainException::make('referral_not_pending');
            }
            $locked->update(['status' => 'rejected', 'rejection_reason' => Str::limit(trim($reason), 250, ''), 'rejected_by' => $staff->id, 'rejected_at' => now()]);
            $this->audit->log('marketing.referral_rejected', $locked, ['status' => 'pending'], ['status' => 'rejected', 'reason' => $locked->rejection_reason], actor: $staff);
        });
    }

    /** @return list<string> shared_device (set when the friend signed up) | same_phone | same_address */
    public function fraudFlags(User $a, User $b): array
    {
        $flags = [];
        $phones = fn (User $u) => collect([$u->phone])->merge($u->addresses()->limit(20)->pluck('recipient_phone'))->map(fn ($p) => preg_replace('/\D+/', '', (string) $p))->filter(fn ($p) => strlen($p) >= 7)->unique();
        if ($phones($a)->intersect($phones($b))->isNotEmpty()) {
            $flags[] = 'same_phone';
        }
        $places = fn (User $u) => $u->addresses()->limit(20)->get()->toBase()->map(fn ($x) => $x->zone_number && $x->street_number && $x->building_number
            ? strtolower("{$x->zone_number}|{$x->street_number}|{$x->building_number}|{$x->unit_number}")
            : ($x->latitude !== null && $x->longitude !== null ? round((float) $x->latitude, 4).','.round((float) $x->longitude, 4) : null))->filter()->unique();
        if ($places($a)->intersect($places($b))->isNotEmpty()) {
            $flags[] = 'same_address';
        }

        return $flags;
    }

    /** @return array{type: ?string, points: ?int, value: ?string, code: ?string, expires: ?string} */
    private function grant(string $side, User $to, Referral $ref): array
    {
        $r = $this->reward($side);
        $none = ['type' => null, 'points' => null, 'value' => null, 'code' => null, 'expires' => null];
        if ($r['type'] === 'balance') {
            $perPoint = $this->loyalty->pointValue()->minor;
            $points = $perPoint > 0 ? (int) round($r['amount']->minor / $perPoint) : 0;
            if ($points <= 0) {
                return $none;
            }
            $this->loyalty->award($to, $points, 'referral', $side === 'referrer' ? 'Referral reward: friend\'s first order' : 'Welcome reward: invited by a friend', 'referral', $ref->id);

            return ['type' => 'balance', 'points' => $points, 'value' => $this->loyalty->valueOf($points)->toDecimal(), 'code' => null, 'expires' => null];
        }
        if ($r['type'] === 'coupon') {
            $promotion = Promotion::find($r['promotion_id']);
            if (! $promotion || ! $promotion->is_active || ! $promotion->requires_coupon) {
                throw DomainException::make('referral_reward_unavailable'); // keeps the referral pending; fix the promotion and it is retried on the next trigger
            }
            $coupon = $this->coupons->issue($promotion->id, $to, $r['prefix'], $r['valid_hours']);

            return ['type' => 'coupon', 'points' => null, 'value' => null, 'code' => $coupon->code, 'expires' => BusinessTime::display($coupon->ends_at)];
        }

        return $none;
    }

    private function close(Referral $ref, string $reason): null
    {
        $ref->update(['status' => 'rejected', 'rejection_reason' => $reason, 'rejected_at' => now()]);

        return null;
    }

    /** In-app + push notification to each rewarded side, in the customer's own language. */
    private function announce(Referral $ref, array $given, User $referrer, User $referee): void
    {
        foreach ([['referrer', $referrer, 'referral.rewarded'], ['friend', $referee, 'referral.friend_rewarded']] as [$side, $user, $event]) {
            $g = $given[$side];
            if (! $g['type']) {
                continue;
            }
            $locale = $user->preferredLocale();
            $reward = $g['type'] === 'coupon'
                ? __('referrals.value_coupon', ['code' => $g['code'], 'date' => $g['expires']], $locale)
                : __('referrals.value_balance', ['amount' => Money::of($g['value'])->format($locale)], $locale);
            $this->notify->notify($user, $event, ['friend' => Str::before(trim($referee->name), ' '), 'reward' => $reward, 'deep_link' => 'albahar://referral']);
        }
    }

    /* ───────────────────────── customer-facing text ───────────────────────── */

    /** One sentence describing a side's reward in the customer's language; null when that side gets nothing. */
    public function rewardText(string $side, string $locale): ?string
    {
        $r = $this->reward($side);
        if ($r['type'] === 'none') {
            return null;
        }
        $name = $r['type'] === 'coupon' ? (Promotion::find($r['promotion_id'])?->localized('name', $locale) ?? '') : '';
        $text = __("referrals.{$side}_{$r['type']}", ['amount' => $r['amount']->format($locale), 'name' => $name], $locale);
        $min = $this->minOrder();

        return $min->isPositive() ? $text.' '.__('referrals.min_order', ['amount' => $min->format($locale)], $locale) : $text;
    }

    public function shareUrl(string $code): string
    {
        $base = trim((string) $this->setting('share_link')) ?: (string) config('app.url');
        $base = parse_url($base, PHP_URL_PATH) === null ? rtrim($base, '/').'/' : $base;

        return $base.(str_contains($base, '?') ? '&' : '?').'ref='.rawurlencode($code);
    }

    public function shareMessage(string $code, string $locale): string
    {
        $tpl = (string) ($this->setting('share_message_'.($locale === 'ar' ? 'ar' : 'en')) ?: config('platform.referrals.share_message_'.($locale === 'ar' ? 'ar' : 'en')));

        return $this->fillMessage($tpl, ['code' => $code, 'link' => $this->shareUrl($code), 'reward' => (string) $this->rewardText('friend', $locale)]);
    }

    /** Replaces {{code}} {{link}} {{reward}}; collapses the gap left by an empty placeholder. */
    public function fillMessage(string $template, array $vars): string
    {
        $out = preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', fn ($m) => (string) ($vars[$m[1]] ?? ''), $template);

        return trim(preg_replace('/[ \t]{2,}/', ' ', (string) $out));
    }

    /** Customer API payload. Hidden entirely while the programme is off. */
    public function summary(User $user): array
    {
        if (! $this->enabled()) {
            return ['enabled' => false];
        }
        $locale = $user->preferredLocale();
        $rows = Referral::where('referrer_id', $user->id)->orderByDesc('created_at')->limit(50)->get(['id', 'referee_id', 'status', 'created_at', 'rewarded_at', 'referrer_reward_type', 'referrer_reward_value', 'referrer_coupon_code']);
        $names = User::whereIn('id', $rows->pluck('referee_id'))->pluck('name', 'id');
        $counts = Referral::where('referrer_id', $user->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $reward = $this->reward('referrer');
        $earned = Money::of((string) Referral::where('referrer_id', $user->id)->where('status', 'rewarded')->sum('referrer_reward_value'));
        $pending = (int) ($counts['pending'] ?? 0);
        $cap = (int) $this->setting('max_rewards_per_referrer');

        return [
            'enabled' => true,
            'code' => $user->referral_code,
            'share_url' => $this->shareUrl((string) $user->referral_code),
            'share_message' => $this->shareMessage((string) $user->referral_code, $locale),
            'referrer_reward' => ['type' => $reward['type'], 'text' => $this->rewardText('referrer', $locale), 'amount' => $reward['type'] === 'balance' ? $reward['amount']->jsonSerialize() : null],
            'friend_reward' => ($f = $this->reward('friend'))['type'] === 'none' ? null : ['type' => $f['type'], 'text' => $this->rewardText('friend', $locale), 'amount' => $f['type'] === 'balance' ? $f['amount']->jsonSerialize() : null],
            'min_order' => $this->minOrder()->isPositive() ? $this->minOrder()->jsonSerialize() : null,
            'validity_days' => max(1, (int) $this->setting('validity_days')),
            'max_rewards' => $cap > 0 ? $cap : null,
            'referred_count' => (int) $counts->sum(),
            'pending_count' => $pending,
            'rewarded_count' => (int) ($counts['rewarded'] ?? 0),
            'earned_total' => $earned->jsonSerialize(),
            'pending_total' => $reward['type'] === 'balance' ? Money::ofMinor($reward['amount']->minor * $pending)->jsonSerialize() : null,
            'friends' => $rows->map(fn (Referral $r) => [
                'name' => Str::before(trim((string) ($names[$r->referee_id] ?? '')), ' ') ?: '—',
                'status' => match ($r->status) { 'rewarded' => 'rewarded', 'pending' => 'pending', default => 'closed' },
                'joined_at' => BusinessTime::iso($r->created_at),
                'rewarded_at' => BusinessTime::iso($r->rewarded_at),
                'reward' => $r->status === 'rewarded' ? ($r->referrer_reward_type === 'coupon' ? ['type' => 'coupon', 'code' => $r->referrer_coupon_code] : ($r->referrer_reward_type === 'balance' ? ['type' => 'balance', 'amount' => Money::of($r->referrer_reward_value)->jsonSerialize()] : null)) : null,
            ])->values()->all(),
        ];
    }
}
