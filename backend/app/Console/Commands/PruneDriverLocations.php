<?php

namespace App\Console\Commands;

use App\Domain\Fulfillment\Models\DriverLocation;
use Illuminate\Console\Command;

class PruneDriverLocations extends Command
{
    protected $signature = 'drivers:prune-locations {--days=7 : days to keep breadcrumbs that belong to no order} {--route-days=365 : days to keep route history per order}';

    protected $description = 'Delete old driver GPS breadcrumbs: stray ones after a week, order routes after a year.';

    public function handle(): int
    {
        // Route history of an order is kept for a year (partner statistics, disputes); stray pings go after a week.
        $n = DriverLocation::whereNull('delivery_id')->where('recorded_at', '<', now()->subDays(max(1, (int) $this->option('days'))))->delete()
            + DriverLocation::where('recorded_at', '<', now()->subDays(max(1, (int) $this->option('route-days'))))->delete();
        $this->info("Pruned {$n} location rows.");

        return self::SUCCESS;
    }
}
