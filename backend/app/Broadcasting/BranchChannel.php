<?php

namespace App\Broadcasting;

use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Support\PermissionRegistry;

/**
 * private-branches.{id}: the employees of the branch who may SEE its orders (orders.view for that branch) and super admins hear its
 * order and delivery changes. A delivery driver of the branch does not, unless their role also lets them see orders: a driver's
 * own jobs come on private-drivers.{id}, so the branch's other orders and the other drivers' positions stay out of their reach.
 */
class BranchChannel
{
    public function join(User $user, string $id): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        // A wall display account listens too (its board redraws when a part or a delivery changes): the events carry no customer data.
        $acl = app(AccessControl::class);

        return BranchEmployee::where('branch_id', $id)->where('user_id', $user->id)->where('is_active', true)->exists()
            && ($acl->allows($user, 'orders.view', $id) || $acl->allows($user, PermissionRegistry::WALL_DISPLAY_KEY, $id));
    }
}
