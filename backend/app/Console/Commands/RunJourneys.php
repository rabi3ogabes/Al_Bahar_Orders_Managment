<?php

namespace App\Console\Commands;

use App\Domain\Marketing\Services\JourneyService;
use Illuminate\Console\Command;

class RunJourneys extends Command
{
    protected $signature = 'journeys:tick';

    protected $description = 'Advance customer journeys: start runs, send due steps, settle conversions';

    public function handle(JourneyService $journeys): int
    {
        $r = $journeys->tick();
        $this->info(sprintf('started %d · sent %d · converted %d · stopped %d', $r['started'], $r['sent'], $r['converted'], $r['stopped']));

        return self::SUCCESS;
    }
}
