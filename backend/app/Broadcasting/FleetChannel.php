<?php

namespace App\Broadcasting;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;

/** private-fleet: only staff who see deliveries of EVERY branch. Branch-scoped staff follow their own drivers on private-branches.{id}. */
class FleetChannel
{
    public function join(User $user): bool
    {
        if (! $user->isStaff()) {
            return false;
        }
        $scope = app(AccessControl::class)->scope($user, 'deliveries.view');

        return $scope->all;
    }
}
