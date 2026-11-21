<?php

namespace App\Console\Commands;

use App\Domain\Reports\Services\OwnerPackSender;
use Illuminate\Console\Command;

/**
 * reports:send-owner-pack: sends the weekly owner pack (docs/guides/OWNER_PACK_AND_CUSTOMER_VALUE.md) when it is due. Aggregates only.
 * Scheduled every five minutes: Schedule::command('reports:send-owner-pack')->everyFiveMinutes()->withoutOverlapping(10)
 * Safe to run any number of times: a week's pack is sent once per channel, and a failed or half-sent one is only retried for the recipients it missed.
 */
class SendOwnerPack extends Command
{
    protected $signature = 'reports:send-owner-pack';

    protected $description = 'Send the weekly owner pack to the owners when it is due (once per week; failed recipients are retried)';

    public function handle(OwnerPackSender $sender): int
    {
        $r = $sender->sendDue();
        $this->info($r['state'].' '.$r['week'].($r['sends'] === [] ? '' : ' ['.implode(', ', $r['sends']).']'));

        return self::SUCCESS;
    }
}
