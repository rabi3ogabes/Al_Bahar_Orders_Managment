<?php

namespace App\Domain\Notifications\Jobs;

use App\Domain\Notifications\Services\PushNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Re-checks one order's live card and pushes it if it changed (see PushNotifier::refreshOrder). Safe to run twice; a
 * second copy queued while one is still waiting is dropped.
 */
class RefreshOrderProgressJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $uniqueFor = 30;

    public function __construct(public readonly string $orderId)
    {
        $this->onQueue('notifications');
    }

    public function uniqueId(): string
    {
        return $this->orderId;
    }

    public function handle(PushNotifier $push): void
    {
        $push->refreshOrder($this->orderId);
    }
}
