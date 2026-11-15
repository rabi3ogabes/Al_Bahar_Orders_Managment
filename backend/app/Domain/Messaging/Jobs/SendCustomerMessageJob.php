<?php

namespace App\Domain\Messaging\Jobs;

use App\Domain\Messaging\Services\MessageDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/** Sends one customer's queued messages of a batch. Safe to run again: only messages still `queued` are touched. */
class SendCustomerMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $batchId, public readonly string $userId)
    {
        $this->onQueue('notifications');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60, 180, 600];
    }

    public function handle(MessageDelivery $delivery): void
    {
        if (! $delivery->deliver($this->batchId, $this->userId)) {
            $this->release(15); // another worker is on this customer right now
        }
    }

    public function failed(Throwable $e): void
    {
        app(MessageDelivery::class)->failRemaining($this->batchId, $this->userId, 'job_failed');
    }
}
