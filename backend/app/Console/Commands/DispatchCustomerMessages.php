<?php

namespace App\Console\Commands;

use App\Domain\Messaging\Services\CustomerMessaging;
use Illuminate\Console\Command;

class DispatchCustomerMessages extends Command
{
    protected $signature = 'messaging:dispatch-due';

    protected $description = 'Start the customer messages whose scheduled time has come, and re-queue sends whose jobs were lost';

    public function handle(CustomerMessaging $messaging): int
    {
        $r = $messaging->dispatchDue();
        $this->info("Started: {$r['started']}, resumed: {$r['resumed']}");

        return self::SUCCESS;
    }
}
