<?php

namespace App\Domain\Marketing\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\LoyaltyAccount;
use App\Domain\Marketing\Models\LoyaltyTransaction;
use App\Domain\Settings\Services\Settings;
use App\Support\DomainException;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Points ledger. Every balance change writes a loyalty_transactions row (balance_after) inside a transaction
 * with the account row locked. Earned points expire FIFO; redemption consumes the oldest earn rows first.
 *
 * Rules (configurable in Settings → loyalty.*):
 *  - Earn: floor(net paid amount × points_per_qar) when an order completes (idempotent per order).
 *  - Redeem: points × redeem_value_per_point QAR, capped at max_redeem_percent of the order after promotions.
 *  - Reverse: on cancellation/refund, redeemed points are returned and earned points clawed back (never below 0).
 */
class LoyaltyService
{
    public function __construct(private readonly Settings $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('loyalty.enabled', true);
    }

    public function account(User $user): LoyaltyAccount
    {
        return LoyaltyAccount::firstOrCreate(['user_id' => $user->id], ['balance' => 0, 'lifetime_earned' => 0, 'tier' => 'classic']);
    }

    public function pointValue(): Money
    {
        return Money::of((string) $this->settings->get('loyalty.redeem_value_per_point', '0.05'));
    }

    /** Maximum points redeemable against $basket (after promotions). */
    public function maxRedeemable(User $user, Money $basket): int
    {
        if (! $this->enabled() || ! $basket->isPositive()) {
            return 0;
        }
        $balance = $this->account($user)->balance;
        $capValue = $basket->percent((string) $this->settings->get('loyalty.max_redeem_percent', 30));
        $perPoint = $this->pointValue()->minor;

        return $perPoint > 0 ? min($balance, intdiv($capValue->minor, $perPoint)) : 0;
    }

    public function valueOf(int $points): Money
    {
        return Money::ofMinor($points * $this->pointValue()->minor);
    }

    public function pointsToEarn(Money $net): int
    {
        if (! $this->enabled() || ! $net->isPositive()) {
            return 0;
        }
        $rate = (int) $this->settings->get('loyalty.points_per_qar', 1);

        return intdiv($net->minor * $rate, 100);
    }

    public function redeem(User $user, int $points, string $orderId): void
    {
        if ($points <= 0) {
            return;
        }
        DB::transaction(function () use ($user, $points, $orderId) {
            $account = LoyaltyAccount::whereKey($this->account($user)->id)->lockForUpdate()->first();
            if ($account->balance < $points) {
                throw DomainException::make('loyalty_insufficient');
            }
            $this->consumeFifo($account, $points);
            $this->write($account, 'redeem', -$points, $orderId, 'Redeemed on order');
        });
    }

    public function earnForOrder(User $user, Money $net, string $orderId): int
    {
        $points = $this->pointsToEarn($net);
        if ($points <= 0 || LoyaltyTransaction::where('order_id', $orderId)->where('type', 'earn')->exists()) {
            return 0;
        }

        return DB::transaction(function () use ($user, $points, $orderId) {
            $account = LoyaltyAccount::whereKey($this->account($user)->id)->lockForUpdate()->first();
            $account->lifetime_earned += $points;
            $this->write($account, 'earn', $points, $orderId, 'Order completed', now()->addDays((int) $this->settings->get('loyalty.points_expiry_days', 365)), remaining: $points);
            $this->updateTier($account);

            return $points;
        });
    }

    public function award(User $user, int $points, string $type, string $reason, ?string $referenceType = null, ?string $referenceId = null): void
    {
        DB::transaction(function () use ($user, $points, $type, $reason, $referenceType, $referenceId) {
            $account = LoyaltyAccount::whereKey($this->account($user)->id)->lockForUpdate()->first();
            $account->lifetime_earned += max(0, $points);
            $this->write($account, $type, $points, null, $reason, now()->addDays((int) $this->settings->get('loyalty.points_expiry_days', 365)), $points, $referenceType, $referenceId);
        });
    }

    /**
     * Manual staff adjustment with a mandatory reason. Credits expire like earned points; debits consume the
     * oldest points first and can never take the balance below zero.
     */
    public function adjust(User $customer, int $points, string $reason, User $staff): LoyaltyTransaction
    {
        if ($points === 0 || trim($reason) === '') {
            throw DomainException::make('quantity_invalid');
        }

        return DB::transaction(function () use ($customer, $points, $reason, $staff) {
            $account = LoyaltyAccount::whereKey($this->account($customer)->id)->lockForUpdate()->first();
            $before = $account->balance;
            if ($points < 0) {
                if ($account->balance < -$points) {
                    throw DomainException::make('loyalty_insufficient');
                }
                $this->consumeFifo($account, -$points);
                $this->write($account, 'adjust', $points, null, $reason, refType: 'staff', refId: $staff->id);
            } else {
                $account->lifetime_earned += $points;
                $this->write($account, 'adjust', $points, null, $reason, now()->addDays((int) $this->settings->get('loyalty.points_expiry_days', 365)), $points, 'staff', $staff->id);
            }
            app(AuditLogger::class)->log('loyalty.adjusted', $account, ['balance' => $before], ['balance' => $account->balance], ['points' => $points, 'reason' => $reason, 'customer' => $customer->id], actor: $staff);

            return LoyaltyTransaction::where('loyalty_account_id', $account->id)->latest('id')->first();
        });
    }

    /** Return redeemed points and claw back earned points for an order (idempotent). */
    public function reverseForOrder(User $user, string $orderId, string $reason = 'Order cancelled/refunded'): void
    {
        DB::transaction(function () use ($user, $orderId, $reason) {
            $account = LoyaltyAccount::whereKey($this->account($user)->id)->lockForUpdate()->first();
            if (LoyaltyTransaction::where('order_id', $orderId)->where('type', 'reverse')->exists()) {
                return;
            }
            $redeemed = (int) -LoyaltyTransaction::where('order_id', $orderId)->where('type', 'redeem')->sum('points');
            $earned = (int) LoyaltyTransaction::where('order_id', $orderId)->where('type', 'earn')->sum('points');
            $delta = $redeemed - min($earned, $account->balance + $redeemed);
            if ($delta === 0 && $redeemed === 0 && $earned === 0) {
                return;
            }
            if ($earned > 0) {
                LoyaltyTransaction::where('order_id', $orderId)->where('type', 'earn')->update(['remaining' => 0]);
            }
            $this->write($account, 'reverse', $delta, $orderId, $reason, $redeemed > 0 ? now()->addDays(90) : null, $redeemed > 0 ? $redeemed : null);
        });
    }

    /** Expire earn rows past their date (FIFO remaining). Safe to run repeatedly. */
    public function expireDue(): int
    {
        $count = 0;
        LoyaltyTransaction::whereIn('type', ['earn', 'referral', 'adjust', 'reverse'])->where('remaining', '>', 0)->where('expires_at', '<', now())
            ->orderBy('id')->limit(1000)->get()->groupBy('loyalty_account_id')->each(function ($rows, $accountId) use (&$count) {
                DB::transaction(function () use ($rows, $accountId, &$count) {
                    $account = LoyaltyAccount::whereKey($accountId)->lockForUpdate()->first();
                    $total = 0;
                    foreach ($rows as $row) {
                        $total += $row->remaining;
                        $row->update(['remaining' => 0]);
                    }
                    $total = min($total, $account->balance);
                    if ($total > 0) {
                        $this->write($account, 'expire', -$total, null, 'Points expired');
                        $count += $total;
                    }
                });
            });

        return $count;
    }

    private function consumeFifo(LoyaltyAccount $account, int $points): void
    {
        $left = $points;
        foreach (LoyaltyTransaction::where('loyalty_account_id', $account->id)->where('remaining', '>', 0)->orderBy('expires_at')->orderBy('id')->lockForUpdate()->get() as $row) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, $row->remaining);
            $row->update(['remaining' => $row->remaining - $take]);
            $left -= $take;
        }
    }

    private function write(LoyaltyAccount $account, string $type, int $points, ?string $orderId, string $reason, $expiresAt = null, ?int $remaining = null, ?string $refType = null, ?string $refId = null): void
    {
        $account->balance = max(0, $account->balance + $points);
        $account->save();
        LoyaltyTransaction::create([
            'loyalty_account_id' => $account->id, 'type' => $type, 'points' => $points, 'balance_after' => $account->balance,
            'remaining' => $remaining, 'order_id' => $orderId, 'reference_type' => $refType, 'reference_id' => $refId,
            'expires_at' => $expiresAt, 'reason' => $reason, 'actor_id' => auth()->id(),
        ]);
    }

    private function updateTier(LoyaltyAccount $account): void
    {
        $tier = match (true) {
            $account->lifetime_earned >= 100000 => 'platinum',
            $account->lifetime_earned >= 40000 => 'gold',
            $account->lifetime_earned >= 10000 => 'silver',
            default => 'classic',
        };
        if ($tier !== $account->tier) {
            $account->update(['tier' => $tier]);
        }
    }
}
