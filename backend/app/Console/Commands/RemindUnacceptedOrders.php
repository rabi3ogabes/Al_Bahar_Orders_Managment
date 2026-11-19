<?php

namespace App\Console\Commands;

use App\Domain\Operations\Services\OrderAlerts;
use Illuminate\Console\Command;

/**
 * orders:remind-unaccepted: "this order is still waiting" for the branch manager and the order team, once when a new order turns amber and once
 * when it turns red (Settings > Operations clock). Idempotent through `ops_reminders`; quiet while the branch is closed.
 * Scheduled every minute: Schedule::command('orders:remind-unaccepted')->everyMinute()->withoutOverlapping(10)
 */
class RemindUnacceptedOrders extends Command
{
    protected $signature = 'orders:remind-unaccepted';

    protected $description = 'Remind the branch team of new orders nobody has accepted in time (once per threshold crossed)';

    public function handle(OrderAlerts $alerts): int
    {
        $r = $alerts->remindUnaccepted();
        $this->info("Checked {$r['checked']} waiting order part(s); sent {$r['reminded']} reminder(s) to {$r['people']} person(s).");

        return self::SUCCESS;
    }
}
