<?php

namespace App\Domain\Marketing\Jobs;

use App\Domain\Marketing\Services\CampaignService;
use App\Domain\Notifications\Models\MarketingCampaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Delivers a claimed campaign to its audience in the background, a few minutes of work at a time. When the time budget runs out it queues itself
 * again and carries on where it stopped; if the whole thing is lost (a deploy kills the worker) the scheduler picks the campaign up again after
 * CampaignService::STALE_MINUTES. Either way no customer is messaged twice: CampaignService records each one before sending.
 */
class DeliverCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** The job is repeatable and idempotent, so a crashed attempt may simply be tried again. */
    public int $tries = 3;

    /** Longer than the time budget plus the chunk in flight, shorter than the scheduler's patience. */
    public int $timeout = 300;

    /** @param int $budgetSeconds how long this job may work before it hands the rest to a new job */
    public function __construct(public readonly string $campaignId, public readonly int $budgetSeconds = CampaignService::TIME_BUDGET_SECONDS)
    {
        $this->onQueue('notifications');
    }

    public function handle(CampaignService $campaigns): void
    {
        $campaign = MarketingCampaign::find($this->campaignId);
        if ($campaign === null) {
            return;
        }
        if (! $campaigns->deliver($campaign, $this->budgetSeconds)) {
            self::dispatch($this->campaignId); // more customers are left: continue in a fresh job instead of holding this worker
        }
    }
}
