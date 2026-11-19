<?php

namespace App\Console\Commands;

use App\Domain\Fulfillment\Services\DispatchService;
use Illuminate\Console\Command;

/**
 * dispatch:watchdog: nothing waits silently on the driver side.
 *
 *  - a delivery given to a driver who did not accept it within `dispatch.accept_timeout_min` (5) is taken back and offered to the next driver
 *    (at most `dispatch.max_auto_attempts` (3) drivers; after that it waits for a person and the branch managers are told);
 *  - a driver on duty who has not been heard from for `dispatch.stale_duty_min` (20) minutes is set off duty, and the jobs he had not accepted
 *    are taken back. Jobs he accepted or has on the road are never touched: the managers are told he went quiet carrying them.
 *
 * Idempotent and safe to run twice at once (row locks). Scheduled every minute:
 * Schedule::command('dispatch:watchdog')->everyMinute()->withoutOverlapping(10)->onOneServer()
 */
class DispatchWatchdog extends Command
{
    protected $signature = 'dispatch:watchdog';

    protected $description = 'Take unaccepted jobs back from silent drivers and set drivers who went quiet off duty';

    public function handle(DispatchService $dispatch): int
    {
        $r = $dispatch->watchdog();
        $this->info("Took back {$r['released']} job(s), gave {$r['reassigned']} to another driver, {$r['gave_up']} waiting for a person; set {$r['off_duty']} silent driver(s) off duty ({$r['quiet_with_jobs']} still carrying jobs).");

        return self::SUCCESS;
    }
}
