<?php

use App\Broadcasting\BranchChannel;
use App\Broadcasting\DriverChannel;
use App\Broadcasting\FleetChannel;
use App\Broadcasting\OrderChannel;
use Illuminate\Support\Facades\Broadcast;

/*
| Auction lot channels are public (bids are public data, bidder identity is masked in payloads).
| Private per-user channels carry outbid/win notifications.
*/
Broadcast::channel('users.{id}', fn ($user, string $id) => (string) $user->id === $id, ['guards' => ['sanctum', 'web']]);

// Staff apps: everyone working at a branch hears that branch's order/delivery changes; a driver hears their own jobs.
Broadcast::channel('branches.{id}', BranchChannel::class, ['guards' => ['sanctum', 'web']]);
Broadcast::channel('drivers.{id}', DriverChannel::class, ['guards' => ['sanctum', 'web']]);
Broadcast::channel('orders.{id}', OrderChannel::class, ['guards' => ['sanctum', 'web']]);
Broadcast::channel('fleet', FleetChannel::class, ['guards' => ['sanctum', 'web']]);

// Mobile clients authorise private channels with their Sanctum bearer token: POST /api/v1/broadcasting/auth.
Broadcast::routes(['prefix' => 'api/v1', 'middleware' => ['auth:sanctum']]);
