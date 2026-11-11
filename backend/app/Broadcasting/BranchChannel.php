<?php

namespace App\Broadcasting;

use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Identity\Models\User;

/** private-branches.{id}: the branch's active employees (and super admins) hear its order and delivery changes. */
class BranchChannel
{
    public function join(User $user, string $id): bool
    {
        return $user->isSuperAdmin()
            || BranchEmployee::where('branch_id', $id)->where('user_id', $user->id)->where('is_active', true)->exists();
    }
}
