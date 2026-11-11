<?php

namespace App\Broadcasting;

use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;

/** private-orders.{id}: only the customer who placed the order follows its driver. */
class OrderChannel
{
    public function join(User $user, string $id): bool
    {
        return Order::where('id', $id)->where('user_id', $user->id)->exists();
    }
}
