<?php

namespace App\Broadcasting;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;

/**
 * private-live-orders: staff who see orders of EVERY branch hear all new orders and status changes.
 * private-live-orders.{branchId}: staff allowed to see that branch's orders (same rule as the orders list).
 */
class LiveOrdersChannel
{
    public function join(User $user, ?string $branch = null): bool
    {
        if (! $user->isStaff()) {
            return false;
        }
        $access = app(AccessControl::class);

        return $branch === null ? $access->scope($user, 'orders.view')->all : $access->allows($user, 'orders.view', $branch);
    }
}
