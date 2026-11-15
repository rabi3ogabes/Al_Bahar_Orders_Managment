<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use Illuminate\Database\Eloquent\Builder;

/** The customers a staff member may see (and therefore message): everyone for company-wide staff, otherwise those who ordered from their branches. */
class CustomerScope
{
    /** @return Builder<User> */
    public static function for(User $viewer): Builder
    {
        $scope = app(AccessControl::class)->scope($viewer, 'customers.view');
        $q = User::query()->where('type', 'customer');
        if (! $scope->all) {
            $q->whereIn('id', Order::query()->select('user_id')->whereIn('id', $scope->apply(OrderFulfillment::query()->select('order_id')->getQuery(), 'branch_id')));
        }

        return $q;
    }
}
