<?php

namespace App\Console\Commands;

use App\Domain\Cms\Services\ThemeService;
use Illuminate\Console\Command;

class SeedThemePresets extends Command
{
    protected $signature = 'theme:seed-presets';

    protected $description = 'Create the default Qatar look and the Qatar event themes (existing themes are left as edited)';

    public function handle(ThemeService $themes): int
    {
        $this->info('Created '.($themes->seedPresets() + $themes->seedDesigns()).' theme(s).');

        return self::SUCCESS;
    }
}
