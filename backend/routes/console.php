<?php

use App\Domain\Auctions\Services\LotService;
use App\Domain\Inventory\Services\ReservationService;
use App\Domain\Marketing\Services\CampaignService;
use App\Domain\Marketing\Services\LoyaltyService;
use App\Domain\Notifications\Models\MarketingCampaign;
use App\Domain\Payments\Services\PaymentService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
| Scheduled jobs (run `php artisan schedule:work` locally; the Docker "scheduler" service runs `schedule:run`).
| Every job is idempotent and safe to run concurrently (row locks + status checks); onOneServer() adds a Redis lock.
*/

Artisan::command('orders:expire-unpaid', fn (PaymentService $p) => $this->info('Expired unpaid orders: '.$p->expireUnpaidOrders()))
    ->purpose('Cancel online-payment orders whose payment window elapsed and release their stock');
Artisan::command('inventory:expire-reservations', fn (ReservationService $r) => $this->info('Expired reservations: '.$r->expireDue()))
    ->purpose('Release stock reservations past their expiry (safety net)');
Artisan::command('payments:reconcile-pending', fn (PaymentService $p) => $this->info('Checked: '.$p->reconcilePending()))
    ->purpose('Confirm pending online payments with the provider status API');
Artisan::command('loyalty:expire-points', fn (LoyaltyService $l) => $this->info('Expired points: '.$l->expireDue()))
    ->purpose('Expire loyalty points past their expiry date');

Schedule::command('drivers:prune-locations')->dailyAt('03:30')->timezone('Asia/Qatar')->onOneServer();
Schedule::command('orders:expire-unpaid')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('inventory:expire-reservations')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('payments:reconcile-pending')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('loyalty:expire-points')->dailyAt('02:15')->timezone('Asia/Qatar')->onOneServer();

Artisan::command('auctions:tick', fn (LotService $l) => $this->info(json_encode($l->tick())))
    ->purpose('Open due lots, close ended lots (server time) and apply unpaid-winner policy');
Schedule::command('auctions:tick')->everyFiveSeconds()->withoutOverlapping(1)->onOneServer();

Artisan::command('marketing:abandoned-carts', fn (CampaignService $c) => $this->info('Reminded: '.$c->remindAbandonedCarts()))
    ->purpose('Send consent-aware abandoned cart reminders within frequency limits');
Artisan::command('marketing:send-scheduled', function (CampaignService $c) {
    MarketingCampaign::where('status', 'scheduled')->where('scheduled_at', '<=', now())->get()->each(fn ($m) => $c->send($m));
})->purpose('Send campaigns whose scheduled time has arrived');
Schedule::command('marketing:abandoned-carts')->everyFifteenMinutes()->onOneServer();
Schedule::command('marketing:send-scheduled')->everyMinute()->withoutOverlapping()->onOneServer();

Schedule::command('journeys:tick')->everyMinute()->withoutOverlapping(5)->onOneServer();
