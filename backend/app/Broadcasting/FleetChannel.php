<?php

namespace App\Broadcasting;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;

/** private-fleet: staff who may view deliveries (the dashboard fleet map). */
class FleetChannel
{
    public function join(User $user): bool
    {
        if (! $user->isStaff()) {
            return false;
        }
        $scope = app(AccessControl::class)->scope($user, 'deliveries.view');

        return $scope->all || $scope->branchIds !== [];
    }
}
