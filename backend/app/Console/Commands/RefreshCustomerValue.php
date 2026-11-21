<?php

namespace App\Console\Commands;

use App\Domain\Reports\Services\CustomerValue;
use Illuminate\Console\Command;

/**
 * customers:refresh-value: rebuilds the customer-value figures behind Sales > Customer value (docs/guides/OWNER_PACK_AND_CUSTOMER_VALUE.md).
 * Scheduled every 30 minutes: Schedule::command('customers:refresh-value')->everyThirtyMinutes()->withoutOverlapping(10)
 * One pass over the orders, never per page view. Safe to run any number of times (an upsert, and one run at a time).
 */
class RefreshCustomerValue extends Command
{
    protected $signature = 'customers:refresh-value';

    protected $description = 'Rebuild what each customer is worth (the lists of top customers and customers who have gone quiet)';

    public function handle(CustomerValue $value): int
    {
        $r = $value->refresh();
        $this->info($r['skipped'] ? 'Another run is already rebuilding it.' : "Customer value rebuilt: {$r['rows']} row(s).");

        return self::SUCCESS;
    }
}
