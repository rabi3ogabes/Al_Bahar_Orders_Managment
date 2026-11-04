<?php

namespace App\Domain\Identity\Events;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class CustomerRegistered
{
    use Dispatchable;

    public function __construct(public readonly User $user, public readonly ?string $referralCode = null, public readonly ?string $deviceId = null) {}
}
