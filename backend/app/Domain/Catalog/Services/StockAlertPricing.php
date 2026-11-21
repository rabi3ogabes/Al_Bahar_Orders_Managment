<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\StockAlert;
use App\Domain\Deals\Services\DealBlocks;
use App\Domain\Deals\Services\DealService;
use App\Domain\Pricing\Services\PriceResolver;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * What a customer would pay for a variant right now at a branch, and whether they could buy it: the effective price of the catalogue (variant and branch
 * overrides, sales inside their window) and, while a countdown offer is running and has units left, the deal price when that is lower. A deal that has not
 * started, has ended, is paused or is sold out counts for nothing, so a price "drop" that only exists on paper is never announced.
 *
 * Built for batches: a page of alerts costs a fixed number of queries (variants, branches, branch settings, stock and deals are each read once), never one
 * per alert.
 */
final class StockAlertPricing
{
    public function __construct(
        private readonly PriceResolver $prices,
        private readonly CatalogPresenter $catalog,
        private readonly DealBlocks $deals,
        private readonly DealService $dealService,
    ) {}

    /**
     * @param  Collection<int, StockAlert>  $alerts
     * @return array<string, array{price: ?Money, in_stock: bool}> alert id => what it costs now (null = cannot be bought there) and whether it is in stock
     */
    public function forAlerts(Collection $alerts): array
    {
        $pairs = [];
        foreach ($alerts as $a) {
            $pairs[$a->id] = ['variant_id' => $a->product_variant_id, 'branch_id' => $a->branch_id];
        }

        return $this->forPairs($pairs);
    }

    /**
     * @param  array<string, array{variant_id: string, branch_id: ?string}>  $pairs  any key => the variant and the branch (null = no preferred branch)
     * @return array<string, array{price: ?Money, in_stock: bool}>
     */
    public function forPairs(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }
        $pairs = collect($pairs);
        $variants = ProductVariant::with('product')->whereIn('id', $pairs->pluck('variant_id')->unique()->values())->get()->keyBy('id');
        $branches = Branch::whereIn('id', $pairs->pluck('branch_id')->filter()->unique()->values())->get()->keyBy('id');
        $entries = $this->deals->load($variants->pluck('product_id')->unique()->values(), null);
        $now = now();
        $out = [];
        foreach ($pairs->groupBy(fn (array $p) => $p['branch_id'] ?? '', preserveKeys: true) as $branchId => $group) {
            $branch = $branchId === '' ? null : $branches->get($branchId);
            $inBranch = $group->map(fn (array $p) => $variants->get($p['variant_id']))->filter()->unique('id');
            $this->prices->prime($inBranch, $branch?->id);
            $units = $this->catalog->stockMap($inBranch->pluck('id')->all(), $branch?->id);
            foreach ($group as $key => $p) {
                $variant = $variants->get($p['variant_id']);
                $out[$key] = ['price' => null, 'in_stock' => false];
                $price = $variant && ($branchId === '' || $branch) ? $this->prices->resolve($variant, $branch) : null;
                if ($price === null) {
                    continue; // not sold at that branch (or gone): nothing to compare, nothing to announce
                }
                $final = $price->final();
                $entry = $entries[$variant->product_id] ?? null;
                if ($entry !== null && $this->dealService->isRunning($entry['deal'], $now) && $entry['row']->remaining() !== 0) {
                    $dealPrice = $this->deals->variantPrice($entry, $price);
                    $final = $dealPrice !== null && $dealPrice->lessThan($final) ? $dealPrice : $final;
                }
                $out[$key] = ['price' => $final, 'in_stock' => ($units[$variant->id] ?? 0) > 0];
            }
        }

        return $out;
    }
}
