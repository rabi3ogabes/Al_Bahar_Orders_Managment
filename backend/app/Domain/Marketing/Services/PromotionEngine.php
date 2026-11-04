<?php

namespace App\Domain\Marketing\Services;

use App\Domain\Catalog\Models\Category;
use App\Domain\Checkout\Data\AppliedDiscount;
use App\Domain\Checkout\Data\QuoteLine;
use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Models\CouponUsage;
use App\Domain\Marketing\Models\Promotion;
use App\Support\DomainException;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Server-authoritative promotions & coupons.
 *
 * TYPES: percentage, fixed, flash_sale (time-boxed percentage), free_delivery, bogo (buy X get Y at Z% off; the cheapest
 * eligible units are discounted), bundle (buy N eligible units → value% off those lines).
 * SCOPE: whole order, specific products, or categories (incl. sub-categories); optional branch, customer-group and
 * first-order restrictions; minimum basket; maximum discount; overall & per-customer usage limits; start/end window.
 *
 * STACKING RULES (deterministic):
 *   1. Free-delivery promotions always combine with item discounts (they only waive delivery fees).
 *   2. Item promotions flagged is_stackable apply cumulatively in priority order (lower first), each computed on the
 *      amount remaining after earlier discounts.
 *   3. Non-stackable item promotions never combine: the single best one is compared against the total of all
 *      stackable ones, and whichever saves the customer more is applied.
 *   4. At most one coupon code per order; the coupon's promotion then follows rules 1–3.
 *   5. A line's discount can never exceed its subtotal; totals never go below zero.
 */
class PromotionEngine
{
    /**
     * @param  list<QuoteLine>  $lines
     * @return array{discounts: list<AppliedDiscount>, coupon_error: ?string, free_delivery: bool}
     */
    public function evaluate(array $lines, ?User $user, ?string $couponCode, Money $deliveryFees, bool $isAuction = false): array
    {
        $now = now();
        $couponError = null;
        $coupon = null;
        $candidates = Promotion::query()->where('is_active', true)->where('requires_coupon', false)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->with(['products:id', 'categories:id,path', 'branches:id'])->orderBy('priority')->get();

        if ($couponCode) {
            [$coupon, $couponError] = $this->findCoupon($couponCode, $user);
            if ($coupon && ! $candidates->contains('id', $coupon->promotion_id)) {
                $candidates->push($coupon->promotion->load(['products:id', 'categories:id,path', 'branches:id']));
            }
        }

        $eligible = [];
        foreach ($candidates as $promo) {
            if ($isAuction && ! $promo->applies_to_auctions) {
                continue;
            }
            $reason = $this->ineligibility($promo, $lines, $user);
            if ($reason !== null) {
                if ($coupon && $coupon->promotion_id === $promo->id) {
                    $couponError = $reason;
                }

                continue;
            }
            $eligible[] = $promo;
        }

        // Free delivery (rule 1).
        $freeDelivery = collect($eligible)->first(fn ($p) => $p->type === 'free_delivery');
        $discounts = [];
        if ($freeDelivery && $deliveryFees->isPositive()) {
            $discounts[] = new AppliedDiscount($freeDelivery, $this->couponFor($freeDelivery, $coupon), Money::zero(), [], true, $deliveryFees);
        }

        $itemPromos = collect($eligible)->reject(fn ($p) => $p->type === 'free_delivery')->sortBy('priority')->values();
        $stackable = $this->applySequence($itemPromos->where('is_stackable', true)->values(), $lines, $coupon);
        $bestSingle = null;
        foreach ($itemPromos->where('is_stackable', false) as $promo) {
            $applied = $this->applySequence(collect([$promo]), $lines, $coupon);
            if ($applied && ($bestSingle === null || $applied[0]->amount->greaterThan($bestSingle[0]->amount))) {
                $bestSingle = $applied;
            }
        }
        $stackTotal = array_reduce($stackable, fn (Money $c, AppliedDiscount $d) => $c->plus($d->amount), Money::zero());
        $chosen = ($bestSingle && $bestSingle[0]->amount->greaterThan($stackTotal)) ? $bestSingle : $stackable;
        $discounts = array_merge($discounts, $chosen);

        // If a valid coupon was entered but lost to a better automatic promotion, tell the customer why.
        if ($coupon && ! $couponError && ! collect($discounts)->contains(fn ($d) => $d->coupon?->id === $coupon->id)) {
            $couponError = 'coupon_not_combinable';
        }

        // Write per-line allocations onto the lines.
        foreach ($lines as $line) {
            $line->discount = Money::zero();
        }
        foreach ($discounts as $d) {
            foreach ($d->allocations as $key => $amount) {
                foreach ($lines as $line) {
                    if ($line->key === $key) {
                        $line->discount = $line->discount->plus($amount)->min($line->subtotal());
                    }
                }
            }
        }

        return ['discounts' => $discounts, 'coupon_error' => $couponError, 'free_delivery' => $freeDelivery !== null];
    }

    /**
     * Record usage atomically at order placement; throws if a limit was reached meanwhile (race-safe).
     *
     * @param  list<AppliedDiscount>  $discounts
     */
    public function recordUsage(array $discounts, string $orderId, ?User $user): void
    {
        foreach ($discounts as $d) {
            $promo = $d->promotion;
            if ($user && $promo->per_customer_limit) {
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['promo:'.$promo->id.':'.$user->id]);
                $used = CouponUsage::where('promotion_id', $promo->id)->where('user_id', $user->id)->where('status', 'applied')->count();
                if ($used >= $promo->per_customer_limit) {
                    throw DomainException::make('coupon_limit_reached', 409);
                }
            }
            $ok = DB::update('UPDATE promotions SET times_used = times_used + 1 WHERE id = ? AND (usage_limit IS NULL OR times_used < usage_limit)', [$promo->id]);
            if ($ok === 0) {
                throw DomainException::make('coupon_limit_reached', 409);
            }
            if ($d->coupon) {
                if ($user && $d->coupon->per_customer_limit) {
                    $used = CouponUsage::where('coupon_id', $d->coupon->id)->where('user_id', $user->id)->where('status', 'applied')->count();
                    if ($used >= $d->coupon->per_customer_limit) {
                        throw DomainException::make('coupon_limit_reached', 409);
                    }
                }
                $ok = DB::update('UPDATE coupons SET times_used = times_used + 1 WHERE id = ? AND (usage_limit IS NULL OR times_used < usage_limit)', [$d->coupon->id]);
                if ($ok === 0) {
                    throw DomainException::make('coupon_limit_reached', 409);
                }
            }
            CouponUsage::create(['coupon_id' => $d->coupon?->id, 'promotion_id' => $promo->id, 'user_id' => $user?->id, 'order_id' => $orderId,
                'discount_amount' => $d->amount->plus($d->deliveryDiscount)->toDecimal(), 'status' => 'applied']);
        }
    }

    /** Reverse usage when an order is cancelled before fulfillment (frees per-customer limits). */
    public function reverseUsage(string $orderId): void
    {
        foreach (CouponUsage::where('order_id', $orderId)->where('status', 'applied')->lockForUpdate()->get() as $u) {
            $u->update(['status' => 'reversed', 'reversed_at' => now()]);
            DB::update('UPDATE promotions SET times_used = GREATEST(times_used - 1, 0) WHERE id = ?', [$u->promotion_id]);
            if ($u->coupon_id) {
                DB::update('UPDATE coupons SET times_used = GREATEST(times_used - 1, 0) WHERE id = ?', [$u->coupon_id]);
            }
        }
    }

    /** @return array{0: ?Coupon, 1: ?string} */
    /** Validate a code on entry (cart) so the customer sees the reason immediately; basket eligibility is checked at quote time. */
    public function couponError(string $code, ?User $user): ?string
    {
        return $this->findCoupon($code, $user)[1];
    }

    private function findCoupon(string $code, ?User $user): array
    {
        $coupon = Coupon::with('promotion')->where('code', strtoupper(trim($code)))->first();
        $now = now();
        if (! $coupon || ! $coupon->is_active || ! $coupon->promotion?->is_active) {
            return [null, 'coupon_invalid'];
        }
        if (($coupon->starts_at && $coupon->starts_at->isFuture()) || ($coupon->ends_at && $coupon->ends_at->lessThanOrEqualTo($now))
            || ($coupon->promotion->starts_at && $coupon->promotion->starts_at->isFuture()) || ($coupon->promotion->ends_at && $coupon->promotion->ends_at->lessThanOrEqualTo($now))) {
            return [null, 'coupon_expired'];
        }
        if ($coupon->assigned_user_id && $coupon->assigned_user_id !== $user?->id) {
            return [null, 'coupon_invalid'];
        }
        if ($coupon->usage_limit !== null && $coupon->times_used >= $coupon->usage_limit) {
            return [null, 'coupon_limit_reached'];
        }
        if ($user && $coupon->per_customer_limit && CouponUsage::where('coupon_id', $coupon->id)->where('user_id', $user->id)->where('status', 'applied')->count() >= $coupon->per_customer_limit) {
            return [null, 'coupon_limit_reached'];
        }

        return [$coupon, null];
    }

    private function ineligibility(Promotion $p, array $lines, ?User $user): ?string
    {
        if ($p->usage_limit !== null && $p->times_used >= $p->usage_limit) {
            return 'coupon_limit_reached';
        }
        if ($p->customer_group_id && (! $user || ! DB::table('customer_group_members')->where('customer_group_id', $p->customer_group_id)->where('user_id', $user->id)->exists())) {
            return 'coupon_not_eligible';
        }
        if ($p->first_order_only && (! $user || DB::table('orders')->where('user_id', $user->id)->whereNotIn('status', ['cancelled'])->exists())) {
            return 'coupon_first_order_only';
        }
        if ($user && $p->per_customer_limit && CouponUsage::where('promotion_id', $p->id)->where('user_id', $user->id)->where('status', 'applied')->count() >= $p->per_customer_limit) {
            return 'coupon_limit_reached';
        }
        $eligibleLines = $this->eligibleLines($p, $lines);
        if (! $eligibleLines) {
            return 'coupon_not_eligible';
        }
        if ($p->min_basket_amount !== null) {
            $base = array_reduce($eligibleLines, fn (Money $c, QuoteLine $l) => $c->plus($l->subtotal()), Money::zero());
            if ($base->lessThan(Money::of($p->min_basket_amount))) {
                return 'coupon_min_basket';
            }
        }

        return null;
    }

    /** @return list<QuoteLine> */
    private function eligibleLines(Promotion $p, array $lines): array
    {
        $branchIds = $p->branches->pluck('id')->all();
        $productIds = $p->products->pluck('id')->all();
        $categoryIds = $p->scope === 'categories' ? $this->expandCategories($p->categories) : [];

        return array_values(array_filter($lines, function (QuoteLine $l) use ($p, $branchIds, $productIds, $categoryIds) {
            if ($branchIds && ! in_array($l->branch->id, $branchIds, true)) {
                return false;
            }

            return match ($p->scope) {
                'products' => in_array($l->productId(), $productIds, true),
                'categories' => in_array($l->categoryId(), $categoryIds, true),
                default => true,
            };
        }));
    }

    private function expandCategories(Collection $categories): array
    {
        $ids = [];
        foreach ($categories as $c) {
            $ids = array_merge($ids, Category::where('path', 'like', $c->path.$c->id.'/%')->pluck('id')->all(), [$c->id]);
        }

        return array_values(array_unique($ids));
    }

    /** @return list<AppliedDiscount> */
    private function applySequence(Collection $promos, array $lines, ?Coupon $coupon): array
    {
        $remaining = [];
        foreach ($lines as $l) {
            $remaining[$l->key] = $l->subtotal();
        }
        $out = [];
        foreach ($promos as $promo) {
            $eligible = $this->eligibleLines($promo, $lines);
            $alloc = $this->compute($promo, $eligible, $remaining);
            $total = array_reduce($alloc, fn (Money $c, Money $m) => $c->plus($m), Money::zero());
            if (! $total->isPositive()) {
                continue;
            }
            foreach ($alloc as $key => $m) {
                $remaining[$key] = $remaining[$key]->minus($m);
            }
            $out[] = new AppliedDiscount($promo, $this->couponFor($promo, $coupon), $total, $alloc);
        }

        return $out;
    }

    /** @return array<string, Money> lineKey → discount, never exceeding the remaining line amount */
    private function compute(Promotion $p, array $eligible, array $remaining): array
    {
        $base = array_reduce($eligible, fn (Money $c, QuoteLine $l) => $c->plus($remaining[$l->key]), Money::zero());
        if (! $base->isPositive()) {
            return [];
        }
        $amount = match ($p->type) {
            'percentage', 'flash_sale' => $base->percent((string) $p->value),
            'fixed' => Money::of((string) $p->value)->min($base),
            'bundle' => array_sum(array_map(fn ($l) => $l->quantity, $eligible)) >= (int) $p->buy_quantity ? $base->percent((string) $p->value) : Money::zero(),
            'bogo' => null,
            default => Money::zero(),
        };

        if ($p->type === 'bogo') {
            return $this->bogo($p, $eligible, $remaining);
        }
        if ($p->max_discount_amount !== null) {
            $amount = $amount->min(Money::of($p->max_discount_amount));
        }
        $amount = $amount->min($base);
        $weights = [];
        foreach ($eligible as $l) {
            $weights[$l->key] = $remaining[$l->key]->minor;
        }
        $parts = $amount->allocate($weights);

        return array_filter($parts, fn (Money $m) => $m->isPositive());
    }

    /** Buy X get Y: within each group of (X+Y) eligible units, the cheapest Y units get get_discount_percent off. */
    private function bogo(Promotion $p, array $eligible, array $remaining): array
    {
        $buy = max(1, (int) $p->buy_quantity);
        $get = max(1, (int) $p->get_quantity);
        $pct = (string) ($p->get_discount_percent ?? '100');
        $units = [];
        foreach ($eligible as $l) {
            $unit = $l->price->final();
            for ($i = 0; $i < $l->quantity; $i++) {
                $units[] = [$l->key, $unit];
            }
        }
        usort($units, fn ($a, $b) => $b[1]->minor <=> $a[1]->minor); // most expensive first
        $out = [];
        $groupSize = $buy + $get;
        $groups = intdiv(count($units), $groupSize);
        // The cheapest `get` units of the whole eligible set across complete groups are discounted.
        $discounted = array_slice(array_reverse($units), 0, $groups * $get);
        foreach ($discounted as [$key, $unit]) {
            $out[$key] = ($out[$key] ?? Money::zero())->plus($unit->percent($pct));
        }
        if ($p->max_discount_amount !== null) {
            $total = array_reduce($out, fn (Money $c, Money $m) => $c->plus($m), Money::zero());
            $cap = Money::of($p->max_discount_amount);
            if ($total->greaterThan($cap)) {
                $parts = $cap->allocate(array_map(fn (Money $m) => $m->minor, $out));
                $out = $parts;
            }
        }
        foreach ($out as $key => $m) {
            $out[$key] = $m->min($remaining[$key]);
        }

        return $out;
    }

    private function couponFor(Promotion $p, ?Coupon $coupon): ?Coupon
    {
        return $coupon && $coupon->promotion_id === $p->id ? $coupon : null;
    }
}
