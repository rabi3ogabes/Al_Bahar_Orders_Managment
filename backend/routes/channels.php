<?php

use Illuminate\Support\Facades\Broadcast;

/*
| Auction lot channels are public (bids are public data, bidder identity is masked in payloads).
| Private per-user channels carry outbid/win notifications.
*/
Broadcast::channel('users.{id}', fn ($user, string $id) => (string) $user->id === $id);
