<?php

use App\Domain\Auctions\Services\LotService;
use App\Domain\Catalog\Services\RoomService;
use App\Domain\Catalog\Services\StockAlertNotifier;
use App\Domain\Catalog\Services\StockAlertService;
use App\Domain\Cms\Services\HomeContentPurger;
use App\Domain\Deals\Services\DealService;
use App\Domain\Fulfillment\Services\DeliveryStepReplay;
use App\Domain\Fulfillment\Services\SlotOffers;
use App\Domain\Inventory\Services\ReservationService;
use App\Domain\Marketing\Services\CampaignService;
use App\Domain\Marketing\Services\LoyaltyService;
use App\Domain\Orders\Services\SubstitutionService;
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

Artisan::command('substitutions:expire', fn (SubstitutionService $s) => $this->info('Settled: '.$s->expireDue()))
    ->purpose('Settle replacement questions the customer did not answer in time, by the fallback the shop chose');
// A question to the customer about a replacement runs out within a minute of its deadline (docs/guides/RETURNS_AND_SUBSTITUTIONS.md).
Schedule::command('substitutions:expire')->everyMinute()->withoutOverlapping(10)->onOneServer();

Artisan::command('deliveries:process-slot-offers', fn (SlotOffers $o) => $this->info(json_encode($o->processDue())))
    ->purpose('Tell customers whose new-time offer was held back for the night, and close the offers whose time ran out');
// The new times offered to a customer after a failed delivery: held-back messages go out at 08:00, an offer past its time is closed (docs/guides/DELIVERY_FOLLOW_UPS.md).
Schedule::command('deliveries:process-slot-offers')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
Artisan::command('deliveries:prune-step-keys', fn (DeliveryStepReplay $r) => $this->info('Removed: '.$r->prune()))
    ->purpose('Forget the keys of driver steps sent more than a week ago (a step is never sent again after a day)');
// The driver app's outbox sends each step with a key so a retry never records it twice (docs/guides/OFFLINE_DRIVER_AND_STAFF_APPS.md); a week later the key is not needed.
Schedule::command('deliveries:prune-step-keys')->dailyAt('03:50')->timezone('Asia/Qatar')->onOneServer();

Schedule::command('drivers:prune-locations')->dailyAt('03:30')->timezone('Asia/Qatar')->onOneServer();
Schedule::command('push:prune-devices')->dailyAt('03:45')->timezone('Asia/Qatar')->onOneServer();
Schedule::command('orders:expire-unpaid')->everyMinute()->withoutOverlapping(10)->onOneServer();
Schedule::command('inventory:expire-reservations')->everyMinute()->withoutOverlapping(10)->onOneServer();
Schedule::command('deals:refresh-feed')->everyMinute()->withoutOverlapping(10)->onOneServer();
Schedule::command('payments:reconcile-pending')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
// One reminder per limit crossed for an order nobody has accepted (docs/guides/LIVE_OPERATIONS.md); quiet while the branch is closed.
Schedule::command('orders:remind-unaccepted')->everyMinute()->withoutOverlapping(10)->onOneServer();
// The driver side of "nothing waits silently": a job nobody accepted goes to the next driver, a driver who went quiet is set off duty (docs/guides/DRIVER_APP.md).
Schedule::command('dispatch:watchdog')->everyMinute()->withoutOverlapping(10)->onOneServer();
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

// Back-in-stock and price-drop alerts (docs/guides/ALERTS_AFTER_DELIVERY_AND_ROOMS.md): a stock movement or a price edit stays the quick path (it queues a job), this
// sweep is the safety net and the only thing that sees a sale window or a countdown offer starting by itself. Quiet hours, the weekly cap and the switches are read inside.
Artisan::command('alerts:process', fn (StockAlertNotifier $n) => $this->info(json_encode($n->run())))
    ->purpose('Tell customers whose product is back in stock or cheaper (each alert once, never at night)');
Schedule::command('alerts:process')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
Artisan::command('alerts:prune', fn (StockAlertService $a) => $this->info('Removed: '.$a->prune()))
    ->purpose('Remove alerts that expired and back-in-stock alerts that fired a month ago');
Schedule::command('alerts:prune')->dailyAt('04:30')->timezone('Asia/Qatar')->onOneServer();

Artisan::command('rooms:ensure-home', fn (RoomService $r) => $this->info($r->ensureHomeSection() ? 'The "Shop by room" section was added to the home page' : 'Nothing to do'))
    ->purpose('Add the "Shop by room" section to the home page, once (an owner who removes it keeps it removed)');
Schedule::command('rooms:ensure-home')->hourly()->withoutOverlapping(10)->onOneServer();

Artisan::command('banners:purge-deleted {--days=7}', fn (HomeContentPurger $p) => $this->info(json_encode($p->purge((int) $this->option('days')))))
    ->purpose('Remove slides and sliders that have been in the dashboard bin for a week (files included)');
Schedule::command('banners:purge-deleted')->dailyAt('04:10')->timezone('Asia/Qatar')->onOneServer();

// The weekly owner pack by e-mail (docs/guides/OWNER_PACK_AND_CUSTOMER_VALUE.md): a pack goes out once per ISO week and channel, at the weekday and time the owner chose
// (Reports > Owner pack); a failed or half-sent one is only retried for the people it missed. The customer-value figures behind Sales > Customer value are rebuilt in one pass.
Schedule::command('reports:send-owner-pack')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
Schedule::command('customers:refresh-value')->everyThirtyMinutes()->withoutOverlapping(10)->onOneServer();

SchedulerHeartbeat::attach(Schedule::getFacadeRoot());
