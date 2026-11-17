<?php

namespace App\Domain\Deals\Services;

use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\DealProduct;
use App\Domain\Deals\Support\DealDesign;
use App\Domain\Deals\Support\DealPricing;
use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Data\EffectivePrice;
use App\Support\BusinessTime;
use App\Support\Money;

/**
 * The `deal` block of a product card, the product detail and a cart line (docs/api/DEALS.md section 5), built without touching the catalog code:
 * load() reads what a whole page of products needs in three queries (no N+1), block() turns one product's entry into the JSON.
 *
 * Nothing here is cached between requests: the units left change with every order, and an admin's change must show at once.
 */
class DealBlocks
{
    public function __construct(private readonly DealService $deals) {}

    /**
     * The deal each product is in: a running deal first, else the one that starts first (the overlap rule means there is normally just one).
     * A deal that is switched off or ended is not here; a sold-out one still inside its window is (the card says "sold out").
     *
     * @param  iterable<string>  $productIds
     * @return array<string, array{deal: Deal, row: DealProduct, used: ?int, design: array<string, mixed>}> keyed by product id
     */
    public function load(iterable $productIds, ?User $user): array
    {
        $ids = collect($productIds)->unique()->values()->all();
        if ($ids === []) {
            return [];
        }
        $now = now();
        $rows = DealProduct::query()->whereIn('product_id', $ids)->whereHas('deal', fn ($q) => $q->open($now))
            ->with(['deal.products' => fn ($q) => $q->select('id', 'deal_id', 'quantity_total', 'quantity_sold')])->get();
        $picked = $rows->sortBy(fn (DealProduct $r) => [$this->deals->isRunning($r->deal, $now) ? 0 : 1, ($r->deal->starts_at ?? $now)->getTimestamp(), $r->id])->unique('product_id');
        $used = $user ? $this->deals->usedBy($user->id, $picked->pluck('id')->all()) : [];

        $brand = DealDesign::brandPrimary();
        $designs = [];
        $out = [];
        foreach ($picked as $row) {
            $designs[$row->deal_id] ??= DealDesign::resolve($row->deal->design, $brand);
            $out[$row->product_id] = ['deal' => $row->deal, 'row' => $row, 'used' => $user ? ($used[$row->id] ?? 0) : null, 'design' => $designs[$row->deal_id]];
        }

        return $out;
    }

    /**
     * The block of one product, or null when the deal would not make any of its variants cheaper (a fixed price above their own, say).
     *
     * @param  array{deal: Deal, row: DealProduct, used: ?int, design: array<string, mixed>}  $entry
     * @param  iterable<EffectivePrice>  $variantPrices  the normal price of every variant the shopper can buy
     * @return array<string, mixed>|null
     */
    public function block(array $entry, iterable $variantPrices): ?array
    {
        $deal = $entry['deal'];
        $row = $entry['row'];
        $best = null; // [deal price, its normal price]: the cheapest variant ("from")
        foreach ($variantPrices as $price) {
            $dealPrice = DealPricing::unitPrice($deal, $row, $price->final());
            if ($dealPrice !== null && ($best === null || $dealPrice->lessThan($best[0]) || ($dealPrice->equals($best[0]) && $price->final()->lessThan($best[1])))) {
                $best = [$dealPrice, $price->final()];
            }
        }
        $phase = $deal->phase();
        if ($best === null || in_array($phase, [Deal::ENDED, Deal::PAUSED], true)) {
            return null;
        }
        $remaining = $row->remaining();
        $perCustomer = $row->perCustomerLimit($deal);

        return [
            'deal_id' => $deal->id, 'name' => $deal->localized('name'), 'phase' => $phase,
            'starts_at' => BusinessTime::iso($deal->starts_at), 'ends_at' => BusinessTime::iso($deal->ends_at),
            'deal_price' => $best[0]->jsonSerialize(), 'regular_price' => $best[1]->jsonSerialize(), 'percent' => DealPricing::percentOff($best[1], $best[0]),
            'remaining' => $remaining,
            'remaining_percent' => $remaining === null || ! $row->quantity_total ? null : (int) round($remaining * 100 / $row->quantity_total),
            'limit_per_order' => $row->perOrderLimit($deal), 'limit_per_customer' => $perCustomer,
            'customer_remaining' => $entry['used'] === null || $perCustomer === null ? null : max(0, $perCustomer - $entry['used']),
            'sold_out' => $remaining === 0,
            'design' => $entry['design'],
        ];
    }

    /** What one variant costs at the deal price (the detail page's variant rows), or null when it gets no deal. @param array{deal: Deal, row: DealProduct, used: ?int, design: array<string, mixed>} $entry */
    public function variantPrice(array $entry, EffectivePrice $price): ?Money
    {
        return DealPricing::unitPrice($entry['deal'], $entry['row'], $price->final());
    }
}
