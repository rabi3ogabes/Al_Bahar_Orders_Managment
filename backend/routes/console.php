<?php

use App\Domain\Auctions\Services\LotService;
use App\Domain\Cms\Services\HomeContentPurger;
use App\Domain\Deals\Services\DealService;
use App\Domain\Inventory\Services\ReservationService;
use App\Domain\Marketing\Services\CampaignService;
use App\Domain\Marketing\Services\LoyaltyService;
use App\Domain\Payments\Services\PaymentService;
use App\Domain\Reports\Services\SchedulerHeartbeat;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
| Scheduled jobs (run `php artisan schedule:work` locally; the Docker "scheduler" service runs `schedule:run`).
| Every job is idempotent and safe to run concurrently (row locks + status checks); onOneServer() adds a Redis lock.
|
| withoutOverlapping() keeps its lock for 24 HOURS unless told otherwise, and a container killed in the middle of a run (every deploy does that) never
| releases it: the task would then silently not run for a day. Every task that uses it therefore names a short expiry (a few minutes, longer than
| the task ever takes), so a lock left behind is gone before anyone notices.
|
| Every task here also leaves a heartbeat (SchedulerHeartbeat::attach at the end of this file), shown on the dashboard's System health card.
*/

Artisan::command('orders:expire-unpaid', fn (PaymentService $p) => $this->info('Expired unpaid orders: '.$p->expireUnpaidOrders()))
    ->purpose('Cancel online-payment orders whose payment window elapsed and release their stock');
Artisan::command('inventory:expire-reservations', fn (ReservationService $r) => $this->info('Expired reservations: '.$r->expireDue()))
    ->purpose('Release stock reservations past their expiry (safety net)');
Artisan::command('deals:refresh-feed', fn (DealService $d) => $this->info($d->refreshFeed() ? 'A deal started or ended: home feed refreshed' : 'Nothing to refresh'))
    ->purpose('Drop the cached home feed and sitemap on the minute a deal starts or ends');
Artisan::command('payments:reconcile-pending', fn (PaymentService $p) => $this->info('Checked: '.$p->reconcilePending()))
    ->purpose('Confirm pending online payments with the provider status API');
Artisan::command('loyalty:expire-points', fn (LoyaltyService $l) => $this->info('Expired points: '.$l->expireDue()))
    ->purpose('Expire loyalty points past their expiry date');

Schedule::command('drivers:prune-locations')->dailyAt('03:30')->timezone('Asia/Qatar')->onOneServer();
Schedule::command('push:prune-devices')->dailyAt('03:45')->timezone('Asia/Qatar')->onOneServer();
Schedule::command('orders:expire-unpaid')->everyMinute()->withoutOverlapping(10)->onOneServer();
Schedule::command('inventory:expire-reservations')->everyMinute()->withoutOverlapping(10)->onOneServer();
Schedule::command('deals:refresh-feed')->everyMinute()->withoutOverlapping(10)->onOneServer();
Schedule::command('payments:reconcile-pending')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
// One reminder per limit crossed for an order nobody has accepted (docs/guides/LIVE_OPERATIONS.md); quiet while the branch is closed.
Schedule::command('orders:remind-unaccepted')->everyMinute()->withoutOverlapping(10)->onOneServer();
Schedule::command('loyalty:expire-points')->dailyAt('02:15')->timezone('Asia/Qatar')->onOneServer();

Artisan::command('auctions:tick', fn (LotService $l) => $this->info(json_encode($l->tick())))
    ->purpose('Open due lots, close ended lots (server time) and apply unpaid-winner policy');
Schedule::command('auctions:tick')->everyFiveSeconds()->withoutOverlapping(1)->onOneServer();

Artisan::command('marketing:send-scheduled', function (CampaignService $c) {
    $r = $c->sendDue();
    $this->info("Sent {$r['sent']}, missed {$r['missed']}");
})->purpose('Send campaigns whose scheduled time has arrived (one that came due more than a day ago is marked missed, not sent)');
Schedule::command('marketing:send-scheduled')->everyMinute()->withoutOverlapping(10)->onOneServer();

// Customer journeys, including the automatic abandoned-cart reminders (Sales > Abandoned orders): one pass a minute, safe to overlap (runs are leased, deliveries are unique).
Schedule::command('journeys:tick')->everyMinute()->withoutOverlapping(5)->onOneServer();

Schedule::command('messaging:dispatch-due')->everyMinute()->withoutOverlapping(5)->onOneServer();

Artisan::command('banners:purge-deleted {--days=7}', fn (HomeContentPurger $p) => $this->info(json_encode($p->purge((int) $this->option('days')))))
    ->purpose('Remove slides and sliders that have been in the dashboard bin for a week (files included)');
Schedule::command('banners:purge-deleted')->dailyAt('04:10')->timezone('Asia/Qatar')->onOneServer();

SchedulerHeartbeat::attach(Schedule::getFacadeRoot());
