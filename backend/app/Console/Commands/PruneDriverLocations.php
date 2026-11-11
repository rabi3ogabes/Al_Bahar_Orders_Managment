<?php

namespace App\Console\Commands;

use App\Domain\Fulfillment\Models\DriverLocation;
use Illuminate\Console\Command;

class PruneDriverLocations extends Command
{
    protected $signature = 'drivers:prune-locations {--days=7}';

    protected $description = 'Delete driver GPS breadcrumbs older than N days (privacy + table size).';

    public function handle(): int
    {
        $n = DriverLocation::where('recorded_at', '<', now()->subDays(max(1, (int) $this->option('days'))))->delete();
        $this->info("Pruned {$n} location rows.");

        return self::SUCCESS;
    }
}
