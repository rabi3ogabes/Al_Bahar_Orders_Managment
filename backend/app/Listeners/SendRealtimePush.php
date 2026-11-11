<?php

namespace App\Listeners;

use App\Domain\Fulfillment\Events\DispatchUpdated;
use App\Domain\Notifications\Services\PushNotifier;
use App\Domain\Orders\Events\OrderUpdated;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Turns the live-socket events into phone pushes, off the request (queue "notifications"). Laravel discovers it by the handle() types. */
class SendRealtimePush implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public function __construct(private readonly PushNotifier $push) {}

    public function handle(OrderUpdated|DispatchUpdated $event): void
    {
        $event instanceof OrderUpdated ? $this->push->orderUpdated($event) : $this->push->dispatchUpdated($event);
    }
}
