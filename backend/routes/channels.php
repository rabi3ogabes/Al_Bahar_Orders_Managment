<?php

use Illuminate\Support\Facades\Broadcast;

/*
| Auction lot channels are public (bids are public data, bidder identity is masked in payloads).
| Private per-user channels carry outbid/win notifications.
*/
Broadcast::channel('users.{id}', fn ($user, string $id) => (string) $user->id === $id, ['guards' => ['sanctum', 'web']]);

// Mobile clients authorise private channels with their Sanctum bearer token: POST /api/v1/broadcasting/auth.
Broadcast::routes(['prefix' => 'api/v1', 'middleware' => ['auth:sanctum']]);
