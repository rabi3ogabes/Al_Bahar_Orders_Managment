<?php

namespace App\Domain\Checkout\Support;

use App\Domain\Identity\Support\BranchScope;
use App\Domain\Orders\Models\Cart;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which customer carts a branch-restricted staff member may see or change. One rule, used by the abandoned orders page, its actions and
 * "Place order for customer", so the list, the side window and the services can never disagree.
 *
 * A cart belongs to a branch when its preferred branch is in the scope, or — while it has no preferred branch yet — when the delivery zone of
 * its address is served (active zone setting) by a branch in the scope. A cart with neither signal is visible to company-wide roles only.
 */
final class CartVisibility
{
    /**
     * @param  Builder<Cart>  $query  a query on `carts`
     * @return Builder<Cart>
     */
    public static function apply(Builder $query, BranchScope $scope): Builder
    {
        if ($scope->all) {
            return $query;
        }
        if ($scope->branchIds === []) {
            return $query->whereRaw('1 = 0');
        }
        $ids = $scope->branchIds;

        return $query->where(fn ($w) => $w->whereIn('carts.branch_id', $ids)
            ->orWhere(fn ($x) => $x->whereNull('carts.branch_id')->whereExists(fn ($s) => $s->select(DB::raw(1))->from('user_addresses as ua')
                ->join('branch_delivery_zones as bdz', 'bdz.delivery_zone_id', '=', 'ua.delivery_zone_id')
                ->whereColumn('ua.id', 'carts.address_id')->where('bdz.is_active', true)->whereIn('bdz.branch_id', $ids))));
    }

    public static function allows(Cart $cart, BranchScope $scope): bool
    {
        return $scope->all || self::apply(Cart::query()->whereKey($cart->getKey()), $scope)->exists();
    }
}
