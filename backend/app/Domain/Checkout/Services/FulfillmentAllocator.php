<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Services\BranchDiscovery;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\BranchInventory;
use App\Domain\Pricing\Services\PriceResolver;
use Illuminate\Support\Collection;

/**
 * Decides which branch fulfills which units.
 *
 * Strategy (each step configurable via platform.checkout.allocation):
 *   1. Prefer the customer's selected branch if it can supply EVERY line.
 *   2. Otherwise prefer a single branch that can supply everything (fewest fulfillments), ordered by
 *      zone priority, then distance from the customer (only when coordinates were shared), then name.
 *   3. Otherwise, if splitting is allowed, greedily pick the branch that covers the most outstanding units
 *      until all units are covered; a line may be split by quantity across branches.
 *   4. Units no branch can supply are reported as shortages (the quote cannot be placed).
 * The resulting plan (and its extra fees) is always shown to the customer before confirmation.
 */
class FulfillmentAllocator
{
    public function __construct(private readonly PriceResolver $prices) {}

    /**
     * @param  array<string, int>  $wanted  variantId → quantity
     * @param  Collection<int, Branch>  $candidates  eligible branches, already ordered by preference (zone priority)
     * @return array{allocations: array<string, array<string, int>>, shortages: array<string, int>, reason: string}
     *                                                                                                              allocations: branchId → (variantId → qty)
     */
    public function allocate(array $wanted, Collection $candidates, ?string $preferredBranchId, bool $allowSplit, ?float $lat = null, ?float $lng = null): array
    {
        $cfg = config('platform.checkout.allocation');
        $variants = ProductVariant::with('product')->whereIn('id', array_keys($wanted))->get()->keyBy('id');
        $supply = $this->supply($variants, $candidates);

        $ordered = $candidates->values();
        if ($lat !== null && $lng !== null && ($cfg['prefer_nearest'] ?? true)) {
            $ordered = $ordered->sortBy(fn (Branch $b) => [$b->getAttribute('zone_priority') ?? 100,
                $b->latitude !== null ? BranchDiscovery::distanceKm($lat, $lng, (float) $b->latitude, (float) $b->longitude) : 9999])->values();
        }

        $coversAll = fn (string $branchId) => collect($wanted)->every(fn ($qty, $vid) => ($supply[$branchId][$vid] ?? 0) >= $qty);

        if ($preferredBranchId && ($cfg['prefer_selected_branch'] ?? true) && $candidates->contains('id', $preferredBranchId) && $coversAll($preferredBranchId)) {
            return ['allocations' => [$preferredBranchId => $wanted], 'shortages' => [], 'reason' => 'selected_branch'];
        }
        if ($cfg['prefer_fewest_branches'] ?? true) {
            foreach ($ordered as $branch) {
                if ($coversAll($branch->id)) {
                    return ['allocations' => [$branch->id => $wanted], 'shortages' => [], 'reason' => 'single_branch'];
                }
            }
        }
        if (! $allowSplit || ! ($cfg['allow_split'] ?? true)) {
            return ['allocations' => [], 'shortages' => $this->bestSingleShortages($wanted, $supply, $preferredBranchId ?? $ordered->first()?->id), 'reason' => 'no_single_branch'];
        }

        // Greedy cover. Start with the preferred branch so the customer's choice is used as much as possible.
        $outstanding = $wanted;
        $allocations = [];
        $order = $ordered->pluck('id')->all();
        if ($preferredBranchId && in_array($preferredBranchId, $order, true)) {
            $order = array_values(array_unique(array_merge([$preferredBranchId], $order)));
        }
        while (array_sum($outstanding) > 0) {
            $best = null;
            $bestUnits = 0;
            foreach ($order as $branchId) {
                if (isset($allocations[$branchId])) {
                    continue;
                }
                $units = 0;
                foreach ($outstanding as $vid => $qty) {
                    $units += min($qty, $supply[$branchId][$vid] ?? 0);
                }
                // Preferred branch wins ties; otherwise the first (highest priority/nearest) in order.
                if ($units > $bestUnits) {
                    $best = $branchId;
                    $bestUnits = $units;
                }
            }
            if ($best === null) {
                break;
            }
            foreach ($outstanding as $vid => $qty) {
                $take = min($qty, $supply[$best][$vid] ?? 0);
                if ($take > 0) {
                    $allocations[$best][$vid] = $take;
                    $outstanding[$vid] -= $take;
                }
            }
        }

        return [
            'allocations' => $allocations,
            'shortages' => array_filter($outstanding),
            'reason' => count($allocations) > 1 ? 'split' : 'single_branch',
        ];
    }

    /** @return array<string, array<string, int>> branchId → variantId → purchasable available units */
    private function supply(Collection $variants, Collection $branches): array
    {
        $stock = BranchInventory::whereIn('branch_id', $branches->pluck('id'))->whereIn('product_variant_id', $variants->keys())
            ->get(['branch_id', 'product_variant_id', 'on_hand', 'reserved'])
            ->groupBy('branch_id');
        $out = [];
        foreach ($branches as $branch) {
            $this->prices->prime($variants, $branch->id);
            $rows = ($stock[$branch->id] ?? collect())->keyBy('product_variant_id');
            foreach ($variants as $vid => $variant) {
                $available = $this->prices->availability($variant, $branch)->purchasable ? max(0, ($rows[$vid]->on_hand ?? 0) - ($rows[$vid]->reserved ?? 0)) : 0;
                $out[$branch->id][$vid] = $available;
            }
        }

        return $out;
    }

    private function bestSingleShortages(array $wanted, array $supply, ?string $branchId): array
    {
        if (! $branchId) {
            return $wanted;
        }
        $short = [];
        foreach ($wanted as $vid => $qty) {
            $have = $supply[$branchId][$vid] ?? 0;
            if ($have < $qty) {
                $short[$vid] = $qty - $have;
            }
        }

        return $short;
    }
}
