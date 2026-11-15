<?php

namespace App\Console\Commands;

use App\Domain\Identity\Models\UserDevice;
use Illuminate\Console\Command;

class PruneStaleDevices extends Command
{
    protected $signature = 'push:prune-devices {--days=60 : switch off phones and browsers that have not opened the app for this many days}';

    protected $description = 'Switch off push tokens of phones and browsers that have not been seen for a long time (uninstalled apps, cleared browsers).';

    public function handle(): int
    {
        // The apps register on every start, so a token nobody refreshed for two months belongs to an app that is gone. Google would answer
        // UNREGISTERED sooner or later; this stops the wait and keeps the queue free of dead sends. Rows stay (the referral check reads them).
        $cutoff = now()->subDays(max(1, (int) $this->option('days')));
        $n = UserDevice::whereNull('disabled_at')->whereRaw('coalesce(last_seen_at, created_at) < ?', [$cutoff])->update(['disabled_at' => now()]);
        $this->info("Switched off {$n} stale push token(s).");

        return self::SUCCESS;
    }
}
