<?php

namespace App\Console\Commands;

use App\Domain\Identity\Models\UserDevice;
use App\Domain\Notifications\Models\LiveActivityToken;
use App\Domain\Notifications\Services\PushDiagnostics;
use Illuminate\Console\Command;

class PruneStaleDevices extends Command
{
    protected $signature = 'push:prune-devices {--days=60 : switch off phones and browsers that have not opened the app for this many days}';

    protected $description = 'Switch off push tokens of phones and browsers that have not been seen for a long time (uninstalled apps, cleared browsers), and clear old iPhone Live Activity tokens.';

    public function handle(): int
    {
        // The apps register on every start, so a token nobody refreshed for two months belongs to an app that is gone. Google would answer
        // UNREGISTERED sooner or later; this stops the wait and keeps the queue free of dead sends. Rows stay (the referral check reads them).
        $cutoff = now()->subDays(max(1, (int) $this->option('days')));
        $n = UserDevice::whereNull('disabled_at')->whereRaw('coalesce(last_seen_at, created_at) < ?', [$cutoff])->update(['disabled_at' => now()]);
        $this->info("Switched off {$n} stale push token(s).");

        // Live Activity tokens: an activity lasts hours, so an activity token older than a couple of days is dead whatever its state, and a
        // switched-off token of any kind is not needed any more. A phone's push-to-start token follows the same rule as its push token.
        $keep = now()->subDays(max(1, (int) config('platform.live_order.activity_token_keep_days', 2)));
        $deleted = LiveActivityToken::where('kind', LiveActivityToken::KIND_ACTIVITY)->where('created_at', '<', $keep)->delete()
            + LiveActivityToken::whereNotNull('disabled_at')->where('disabled_at', '<', $keep)->delete();
        $starters = LiveActivityToken::where('kind', LiveActivityToken::KIND_START)->whereNull('disabled_at')
            ->whereRaw('coalesce(last_seen_at, created_at) < ?', [$cutoff])->update(['disabled_at' => now()]);
        $this->info("Cleared {$deleted} old live activity token(s), switched off {$starters} stale push-to-start token(s).");

        // What the phones reported about their own push set-up is only useful while it is recent.
        $reports = app(PushDiagnostics::class)->prune(PushDiagnostics::KEEP_DAYS);
        $this->info("Removed {$reports} old phone report(s).");

        return self::SUCCESS;
    }
}
