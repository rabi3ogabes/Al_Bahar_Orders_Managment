<?php

namespace App\Console\Commands;

use App\Support\Scale\ScaleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fills a throw-away database whose name ends in "_scale" with realistic volumes (docs/guides/SCALE_PROOF.md). Never part of the normal test suite.
 */
class ScaleSeed extends Command
{
    protected $signature = 'scale:seed {--orders=1000000 : Orders to create (customers = orders / 5)} {--products=5000 : Products to create} {--fresh : Empty the database first (migrate:fresh)}';

    protected $description = 'Fill a *_scale database with millions of realistic rows to prove the query plans at size (refuses any other database)';

    public function handle(): int
    {
        try {
            ScaleSeeder::assertSafe();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ($this->option('fresh')) {
            $this->call('migrate:fresh', ['--force' => true]);
        } elseif (DB::table('orders')->exists()) {
            $this->error('The scale database already holds orders: run again with --fresh to rebuild it.');

            return self::FAILURE;
        }
        $started = microtime(true);
        (new ScaleSeeder((int) $this->option('orders'), (int) $this->option('products'), fn (string $line) => $this->line($line)))->run();
        $this->info(sprintf('Done in %.1f minutes. Database size: %s', (microtime(true) - $started) / 60, DB::selectOne('select pg_size_pretty(pg_database_size(current_database())) as s')->s));

        return self::SUCCESS;
    }
}
