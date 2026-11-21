<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\StockAlert;
use App\Domain\Inventory\Models\BranchInventory;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Services\NotificationRules;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Pricing\Services\PriceResolver;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tells customers what they asked to be told. Two jobs, both safe to run twice and at the same time:
 *
 *  - {@see self::backInStock()}: an alert whose variant can be bought again (stock in the alert's branch, or in any branch when the customer named none)
 *    is claimed with one UPDATE (`notified_at` is still empty) and only then announced, once, with a link to the product. It looks at the stock NOW, so a
 *    variant that sold out again before the job ran is never announced; the alert simply keeps waiting.
 *  - {@see self::priceDrops()}: a price alert is announced when the price the customer would pay now (sale and running deal included) is lower than the
 *    price they last saw by at least the percentage AND the minimum amount of the settings. The announcement moves the alert's `reference_price` to that new
 *    price in the same UPDATE that claims it, so the same price is never announced twice; a price that rises moves the reference up, so the next fall is a
 *    new drop. Never more than the weekly cap per customer, never while the product is out of stock.
 *
 * Nothing goes out in the quiet hours, while the store's switch or the notification control of the event is off, or (for a price drop, which is
 * marketing-style) to a customer who has not agreed to marketing messages: the alert is left untouched and the next pass decides again. Claim and message
 * are one transaction, so a message that fails to be made gives the alert back. Nothing here calls out to a provider inside the transaction: the push is
 * queued after the commit by NotificationService.
 */
final class StockAlertNotifier
{
    /** Alerts handled per back-in-stock pass; the rest follows on the next pass. */
    private const BATCH = 200;

    private const WEEK_DAYS = 7;

    public function __construct(
        private readonly StockAlertSettings $settings,
        private readonly StockAlertPricing $pricing,
        private readonly NotificationService $notify,
        private readonly NotificationRules $rules,
        private readonly PriceResolver $prices,
    ) {}

    /** One scheduler pass. @return array{back_in_stock: int, price_drop: int} messages made */
    public function run(): array
    {
        return ['back_in_stock' => $this->backInStock(), 'price_drop' => $this->priceDrops()];
    }

    // ── Back in stock ────────────────────────────────────────────────────────────────────────────────────────────────

    /** @param  ?string  $variantId  only the alerts of this variant (the job a stock movement queues); null = every pending alert (the scheduler) */
    public function backInStock(?string $variantId = null): int
    {
        if (! $this->settings->enabled(StockAlert::BACK_IN_STOCK) || ! $this->rules->enabled('stock.back_in_stock') || $this->quiet()) {
            return 0;
        }
        $alerts = StockAlert::query()->where('type', StockAlert::BACK_IN_STOCK)->whereNull('notified_at')->where('expires_at', '>', now())
            ->when($variantId, fn ($q) => $q->where('product_variant_id', $variantId))
            ->whereExists(fn ($s) => $s->from('branch_inventory as bi')->join('branches as b', 'b.id', '=', 'bi.branch_id')
                ->whereColumn('bi.product_variant_id', 'stock_alerts.product_variant_id')->where('b.status', 'active')->whereRaw('bi.on_hand - bi.reserved > 0')
                ->where(fn ($w) => $w->whereNull('stock_alerts.branch_id')->orWhereColumn('bi.branch_id', 'stock_alerts.branch_id')))
            ->with(['user', 'variant.product.translations'])->orderBy('created_at')->orderBy('id')->limit(self::BATCH)->get();
        $stocked = $this->stockedBranches($alerts);
        $branches = Branch::whereIn('id', collect($stocked)->flatten()->unique()->values())->get()->keyBy('id');
        foreach ($branches as $branch) {
            $this->prices->prime($alerts->pluck('variant')->filter(), $branch->id); // branch settings of the whole batch in two queries, not one per alert
        }
        $sent = 0;
        foreach ($alerts as $alert) {
            if (! $this->canBeBought($alert, $stocked[$alert->product_variant_id] ?? [], $branches)) {
                continue; // still waiting: the product is switched off, or not sold where the customer shops
            }
            $sent += $this->announceBackInStock($alert) ? 1 : 0;
        }

        return $sent;
    }

    /**
     * Branches that have the variant on the shelf (active branches, units above zero), per variant.
     *
     * @param  Collection<int, StockAlert>  $alerts
     * @return array<string, list<string>>
     */
    private function stockedBranches(Collection $alerts): array
    {
        if ($alerts->isEmpty()) {
            return [];
        }

        return BranchInventory::query()->whereIn('product_variant_id', $alerts->pluck('product_variant_id')->unique()->values())->whereRaw('on_hand - reserved > 0')
            ->whereHas('branch', fn ($b) => $b->where('status', 'active'))->get(['branch_id', 'product_variant_id'])
            ->groupBy('product_variant_id')->map(fn (Collection $g) => $g->pluck('branch_id')->all())->all();
    }

    /**
     * @param  list<string>  $stockedBranchIds
     * @param  Collection<string, Branch>  $branches
     */
    private function canBeBought(StockAlert $alert, array $stockedBranchIds, Collection $branches): bool
    {
        $variant = $alert->variant;
        if ($variant === null || $variant->product === null) {
            return false;
        }
        $branchIds = $alert->branch_id !== null ? array_values(array_intersect([$alert->branch_id], $stockedBranchIds)) : $stockedBranchIds;
        foreach ($branchIds as $branchId) {
            $branch = $branches->get($branchId);
            if ($branch !== null && $this->prices->availability($variant, $branch)->purchasable) {
                return true;
            }
        }

        return false;
    }

    // ── Price drops ──────────────────────────────────────────────────────────────────────────────────────────────────

    /** @param  ?string  $productId  only the alerts of this product (the job a price change queues); null = every price alert (the scheduler) */
    public function priceDrops(?string $productId = null): int
    {
        if (! $this->settings->enabled(StockAlert::PRICE_DROP) || ! $this->rules->enabled('stock.price_drop') || $this->quiet()) {
            return 0;
        }
        $sent = 0;
        StockAlert::query()->where('type', StockAlert::PRICE_DROP)->where('expires_at', '>', now())->when($productId, fn ($q) => $q->where('product_id', $productId))
            ->with(['user', 'variant.product.translations'])->chunkById(200, function (Collection $alerts) use (&$sent) {
                $sent += $this->scanPrices($alerts);
            });

        return $sent;
    }

    /** @param  Collection<int, StockAlert>  $alerts */
    private function scanPrices(Collection $alerts): int
    {
        $state = $this->pricing->forAlerts($alerts);
        $percent = $this->settings->dropPercent();
        $minMinor = $this->settings->minAmount()->minor;
        $moveUp = [];
        $drops = [];
        foreach ($alerts as $alert) {
            $now = $state[$alert->id]['price'] ?? null;
            if ($now === null || ! ($state[$alert->id]['in_stock'] ?? false)) {
                continue; // cannot be bought there, or not in stock: a cheaper price nobody can use is not news
            }
            $was = $alert->reference_price === null ? null : Money::of($alert->reference_price);
            if ($was === null || $now->greaterThan($was)) {
                $moveUp[] = [$alert->id, $alert->reference_price, $now]; // the customer's picture of the price follows it up: the next fall is a new drop
            } elseif ($was->minus($now)->minor > 0 && $was->minus($now)->minor * 100 >= $was->minor * $percent && $was->minus($now)->minor >= $minMinor) {
                $drops[$alert->user_id][] = ['alert' => $alert, 'now' => $now, 'was' => $was, 'ratio' => $was->minus($now)->minor / max(1, $was->minor)];
            }
        }
        foreach ($moveUp as [$id, $old, $now]) {
            StockAlert::whereKey($id)->when($old === null, fn ($q) => $q->whereNull('reference_price'), fn ($q) => $q->where('reference_price', $old))->update(['reference_price' => $now->toDecimal()]);
        }
        if ($drops === []) {
            return 0;
        }
        $already = $this->sentThisWeek(array_keys($drops));
        $sent = 0;
        foreach ($drops as $userId => $list) {
            $user = $list[0]['alert']->user;
            if ($user === null || ! $user->isActive() || ! $user->marketing_consent_at) {
                continue; // price drops are marketing-style: only for customers who agreed to hear from us
            }
            usort($list, fn (array $a, array $b) => $b['ratio'] <=> $a['ratio']); // the biggest fall first when the cap allows only some
            $left = $this->settings->weeklyCap() - ($already[$userId] ?? 0);
            foreach ($list as $drop) {
                if ($left <= 0) {
                    break;
                }
                if ($this->announcePriceDrop($drop['alert'], $drop['was'], $drop['now'])) {
                    $left--;
                    $sent++;
                }
            }
        }

        return $sent;
    }

    /**
     * Price-drop messages each customer got in the last 7 days, whichever way they were reached (the inbox entry or the push), so the weekly cap holds
     * for a customer who switched the inbox off too.
     *
     * @param  list<string>  $userIds
     * @return array<string, int>
     */
    private function sentThisWeek(array $userIds): array
    {
        $since = now()->subDays(self::WEEK_DAYS);
        $inbox = Notification::whereIn('user_id', $userIds)->where('event', 'stock.price_drop')->where('created_at', '>=', $since)->groupBy('user_id')->selectRaw('user_id, count(*) as n')->pluck('n', 'user_id');
        $push = DB::table('notification_logs')->whereIn('user_id', $userIds)->where('event', 'stock.price_drop')->where('channel', 'push')->where('created_at', '>=', $since)
            ->groupBy('user_id')->selectRaw('user_id, count(*) as n')->pluck('n', 'user_id');
        $out = [];
        foreach ($userIds as $id) {
            $out[$id] = max((int) ($inbox[$id] ?? 0), (int) ($push[$id] ?? 0));
        }

        return $out;
    }

    // ── One message ──────────────────────────────────────────────────────────────────────────────────────────────────

    private function announceBackInStock(StockAlert $alert): bool
    {
        return $this->deliver($alert, 'stock.back_in_stock', fn () => StockAlert::whereKey($alert->id)->whereNull('notified_at')->update(['notified_at' => now()]), []);
    }

    private function announcePriceDrop(StockAlert $alert, Money $was, Money $now): bool
    {
        $claim = fn () => StockAlert::whereKey($alert->id)->where('reference_price', $alert->reference_price)->update(['reference_price' => $now->toDecimal(), 'notified_at' => now()]);

        return $this->deliver($alert, 'stock.price_drop', $claim, ['now' => $now, 'was' => $was]);
    }

    /**
     * Claims the alert and makes the message, in one transaction. The claim is one UPDATE that only matches an alert nobody else has handled
     * (`notified_at` still empty for a back-in-stock alert, the same `reference_price` for a price alert), so two passes at the same time announce once.
     *
     * @param  callable(): int  $claim  the UPDATE; 1 = this pass won the alert
     * @param  array{now?: Money, was?: Money}  $price  the new and the old price (price drop only)
     */
    private function deliver(StockAlert $alert, string $event, callable $claim, array $price): bool
    {
        try {
            return DB::transaction(function () use ($alert, $event, $claim, $price) {
                if ($claim() !== 1) {
                    return false;
                }
                $user = $alert->user;
                $product = $alert->variant->product;
                $byLocale = [];
                foreach ((array) config('platform.supported_locales') as $locale) {
                    $byLocale[$locale] = ['product' => (string) $product->trans('name', $locale)]
                        + ($price === [] ? [] : ['price' => $price['now']->format($locale), 'was' => $price['was']->format($locale),
                            'percent' => (string) (int) floor(($price['was']->minor - $price['now']->minor) * 100 / max(1, $price['was']->minor))]);
                }
                $data = ['deep_link' => 'albahar://products/'.$product->id, 'reference' => $alert->id, 'by_locale' => $byLocale] + $byLocale[$user->preferredLocale()];
                $this->notify->notify($user, $event, $data);

                return true;
            });
        } catch (Throwable $e) {
            report($e); // the transaction gave the alert back: it is tried again on the next pass

            return false;
        }
    }

    private function quiet(): bool
    {
        return $this->settings->quietHours()->contains(CarbonImmutable::now());
    }
}
