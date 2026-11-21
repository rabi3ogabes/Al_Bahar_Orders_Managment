<?php

namespace App\Domain\Catalog\Jobs;

use App\Domain\Catalog\Services\StockAlertNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The quick path of the alerts: stock came back for a variant, or a product's price changed. It waits a short moment (so a variant that flickers between
 * "none" and "one" is judged on how it ended up, and the transaction that changed it has long committed) and then asks {@see StockAlertNotifier} to look
 * at the stock and the price as they are NOW. The scheduler runs the same code for everything every few minutes, so a lost job costs minutes, not an alert.
 */
class ProcessStockAlertsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** How long the job waits before it looks, in seconds. */
    public const DELAY_SECONDS = 45;

    public int $tries = 3;

    /** @param  'back_in_stock'|'price_drop'  $kind  @param  string  $subject  the variant id (back in stock) or the product id (price drop) */
    public function __construct(public readonly string $kind, public readonly string $subject)
    {
        $this->onQueue('default');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(StockAlertNotifier $notifier): void
    {
        $this->kind === 'back_in_stock' ? $notifier->backInStock($this->subject) : $notifier->priceDrops($this->subject);
    }
}
