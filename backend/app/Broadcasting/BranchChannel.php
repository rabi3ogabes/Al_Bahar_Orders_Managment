<?php

namespace App\Broadcasting;

use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;

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

        return BranchEmployee::where('branch_id', $id)->where('user_id', $user->id)->where('is_active', true)->exists()
            && app(AccessControl::class)->allows($user, 'orders.view', $id);
    }
}
