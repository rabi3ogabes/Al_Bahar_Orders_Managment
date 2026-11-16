<?php

namespace App\Domain\Checkout\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Support\BranchScope;
use App\Domain\Orders\Models\Cart;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What an "abandoned cart" is, as one query, and the small count on the menu. Kept apart from AbandonedCartService so the dashboard layout can show
 * the count without building the whole ordering stack on every page.
 *
 * An abandoned cart is a customer's open cart (`status = active`) with at least one line, idle for at least `$idleMinutes` (30 by default), that has
 * not outlived the cart lifetime (`platform.checkout.cart_ttl_days`: older carts expired and cannot be recovered), inside the given branch scope.
 */
final class AbandonedCarts
{
    /** A cart is abandoned once its customer has not touched it for this many minutes. */
    public const MIN_IDLE = 30;

    /** @return Builder<Cart> */
    public static function query(BranchScope $scope, int $idleMinutes = self::MIN_IDLE): Builder
    {
        $q = Cart::query()->where('carts.status', 'active')->whereNotNull('carts.user_id')
            ->whereBetween('carts.last_activity_at', [now()->subDays(self::maxAgeDays()), now()->subMinutes($idleMinutes)])
            ->whereExists(fn ($s) => $s->select(DB::raw(1))->from('cart_items')->whereColumn('cart_items.cart_id', 'carts.id'));

        return CartVisibility::apply($q, $scope);
    }

    public static function maxAgeDays(): int
    {
        return max(1, (int) config('platform.checkout.cart_ttl_days'));
    }

    /** Carts idle for the minimum or more in the staff member's scope; cached for a minute and shared by everyone with the same scope. */
    public static function badge(?User $staff): int
    {
        $access = app(AccessControl::class);
        if (! $access->allows($staff, 'orders.view')) {
            return 0;
        }
        $scope = $access->scope($staff, 'orders.view');

        return (int) Cache::remember(self::badgeKey($scope), 60, fn () => self::query($scope)->count());
    }

    /** Drop the cached count of this staff member's scope (after a discard or an undo, so their own menu is right at once). */
    public static function forgetBadge(User $staff): void
    {
        Cache::forget(self::badgeKey(app(AccessControl::class)->scope($staff, 'orders.view')));
    }

    private static function badgeKey(BranchScope $scope): string
    {
        $ids = $scope->branchIds;
        sort($ids);

        return 'abandoned-badge:'.($scope->all ? 'all' : md5(implode(',', $ids)));
    }
}
