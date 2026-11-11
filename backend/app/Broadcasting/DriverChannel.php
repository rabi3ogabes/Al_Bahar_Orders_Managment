<?php

namespace App\Broadcasting;

use App\Domain\Identity\Models\User;

/** private-drivers.{id}: a driver hears only their own jobs. */
class DriverChannel
{
    public function join(User $user, string $id): bool
    {
        return (string) $user->id === $id;
    }
}
