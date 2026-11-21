<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchDeliveryZone;
use App\Domain\Branches\Models\DeliveryZone;
use App\Domain\Branches\Services\DeliveryZoneResolver;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Checkout\Support\Assembly;
use App\Domain\Settings\Services\Settings;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "When will I get it?" for a basket that is not an order yet: the product page (one line), the cart of a guest (lines sent by the browser or the app)
 * and the cart of a signed-in customer. It picks the branch (or branches) the same way the checkout does, with the very same FulfillmentAllocator
 * (the customer's branch first, then one branch that holds everything, then a split), and asks DeliveryPromise for the date of each parcel.
 * Nothing here writes, reserves or charges anything, and a customer's address is only ever used to find the delivery zone (see the controller).
 *
 * The answer is always usable: a missing address, an empty branch or a closed day never raises an error, it becomes "We will confirm your delivery date".
 */
class BasketPromise
{
    public function __construct(
        private readonly FulfillmentAllocator $allocator,
        private readonly DeliveryPromise $promise,
        private readonly DeliveryZoneResolver $zones,
        private readonly Settings $settings,
    ) {}

    public function enabled(): bool
    {
        return $this->promise->enabled();
    }

    /** The delivery zone of what the customer shared (a saved address' zone, a zone number or a position); null when none of it matches a zone. */
    public function zone(?string $zoneId, ?string $zoneNumber, ?float $lat, ?float $lng): ?DeliveryZone
    {
        return $this->zones->resolve($zoneId, $zoneNumber, $lat, $lng);
    }

    /**
     * @param  list<array{variant_id: string, quantity: int, assembly?: bool}>  $lines  what the customer wants (variants this shop does not know are ignored)
     * @param  array{type?: string, branch_id?: ?string, zone?: ?DeliveryZone, latitude?: ?float, longitude?: ?float, allow_split?: bool}  $where
     * @return array<string, mixed>
     */
    public function forLines(array $lines, array $where = [], ?CarbonImmutable $now = null): array
    {
        $type = ($where['type'] ?? 'delivery') === 'pickup' ? 'pickup' : 'delivery';
        $zone = $type === 'delivery' ? ($where['zone'] ?? null) : null;
        $now ??= BusinessTime::now();
        if (! $this->enabled()) {
            return ['enabled' => false, 'fulfillment_type' => $type, 'status' => 'disabled', 'message' => null, 'detail' => null, 'all_by' => null, 'is_split' => false,
                'location_known' => $zone !== null, 'parcels' => [], 'generated_at' => BusinessTime::iso($now)];
        }

        [$wanted, $assembly, $variants] = $this->wanted($lines);
        [$candidates, $configs] = $this->candidates($type, $zone, $where['branch_id'] ?? null);
        $split = (bool) $this->settings->get('checkout.show_split_shipment', true) && (bool) ($where['allow_split'] ?? true);
        $parcels = [];
        $shortage = false;

        if ($wanted === [] || $candidates->isEmpty()) {
            return $this->result($type, $zone, $parcels, shortage: true, now: $now, none: $candidates->isEmpty() ? 'not_offered' : 'no_date');
        }

        $alloc = $this->allocator->allocate($wanted, $candidates, $where['branch_id'] ?? null, $split,
            isset($where['latitude']) ? (float) $where['latitude'] : null, isset($where['longitude']) ? (float) $where['longitude'] : null);
        $branches = $candidates->keyBy('id');
        foreach ($alloc['allocations'] as $branchId => $units) {
            $parcels[] = $this->promise->parcel($branches[$branchId], $type, $this->parcelLines($units, $variants, $assembly, $type), $configs[$branchId] ?? null, now: $now);
        }
        if ($alloc['shortages'] !== []) {
            // Units no branch holds: the part that is in stock keeps its date, the rest is honest about it. (With nothing in stock anywhere: one unconfirmed parcel.)
            $shortage = $parcels !== [];
            $first = $branches->get((string) ($where['branch_id'] ?? '')) ?? $candidates->first();
            $missing = [];
            foreach ($alloc['shortages'] as $variantId => $qty) {
                $missing[$variantId] = (int) $qty;
            }
            $parcels[] = $this->promise->parcel($first, $type, $this->parcelLines($missing, $variants, $assembly, $type), $configs[$first->id] ?? null, 'out_of_stock', $now);
        }

        return $this->result($type, $zone, $parcels, shortage: $shortage, now: $now);
    }

    /**
     * One product on its own page: the chosen variant (or the product's default) times `$quantity`, the date from the customer's showroom or the best one.
     *
     * @param  array{type?: string, branch_id?: ?string, zone?: ?DeliveryZone, latitude?: ?float, longitude?: ?float}  $where
     * @return array<string, mixed>
     */
    public function forVariant(ProductVariant $variant, int $quantity, bool $assembly, array $where = [], ?CarbonImmutable $now = null): array
    {
        return $this->forLines([['variant_id' => $variant->id, 'quantity' => max(1, $quantity), 'assembly' => $assembly]], $where + ['allow_split' => false], $now);
    }

    /**
     * @param  list<array<string, mixed>>  $parcels
     * @return array<string, mixed>
     */
    private function result(string $type, ?DeliveryZone $zone, array $parcels, bool $shortage, CarbonImmutable $now, ?string $none = null): array
    {
        $summary = $this->promise->summarize($parcels, $type, $shortage);
        if ($parcels === []) {
            $summary['detail'] = __('promise.reason.'.($none ?? 'no_date'), ['branch' => '']);
        }

        return ['enabled' => true, 'fulfillment_type' => $type] + $summary
            // Delivery dates depend on the address: until the zone is known the customer is told so (the date shown is from the best showroom).
            + ['location_known' => $type === 'pickup' || $zone !== null, 'parcels' => $parcels, 'generated_at' => BusinessTime::iso($now)];
    }

    /**
     * Merge the lines per variant and load what the shop knows of them.
     *
     * @param  list<array{variant_id: string, quantity: int, assembly?: bool}>  $lines
     * @return array{0: array<string, int>, 1: array<string, bool>, 2: Collection<string, ProductVariant>}
     */
    private function wanted(array $lines): array
    {
        $max = (int) config('platform.checkout.max_quantity_per_line');
        $variants = ProductVariant::with('product')->whereIn('id', array_map(fn (array $l) => (string) $l['variant_id'], $lines))->get()->keyBy('id');
        $wanted = [];
        $assembly = [];
        foreach ($lines as $l) {
            $id = (string) $l['variant_id'];
            if (! $variants->has($id)) {
                continue;
            }
            $wanted[$id] = min($max, ($wanted[$id] ?? 0) + max(1, (int) $l['quantity']));
            $assembly[$id] = ($assembly[$id] ?? false) || (bool) ($l['assembly'] ?? false);
        }

        return [$wanted, $assembly, $variants];
    }

    /**
     * The branches that may supply the basket and, for delivery to a known zone, how each is configured there.
     *
     * @return array{0: Collection<int, Branch>, 1: array<string, BranchDeliveryZone>}
     */
    private function candidates(string $type, ?DeliveryZone $zone, ?string $preferred): array
    {
        $with = ['hours', 'holidays' => fn ($q) => $q->where('ends_at', '>', now())];
        if ($type === 'delivery' && $zone !== null) {
            $configs = $this->zones->servingBranches($zone);
            $branches = $configs->map(function (BranchDeliveryZone $c): Branch {
                /** @var Branch $branch the FK is NOT NULL */
                $branch = $c->branch;
                $branch->setAttribute('zone_priority', $c->priority);

                return $branch;
            })->values();
            $branches->load($with);

            return [$branches, $configs->keyBy('branch_id')->all()];
        }
        $branches = Branch::active()->where($type === 'pickup' ? 'pickup_enabled' : 'delivery_enabled', true)->with($with)->orderBy('sort_order')->orderBy('name_en')->get();
        if ($preferred !== null && $branches->contains('id', $preferred)) {
            $branches = $branches->sortBy(fn (Branch $b) => $b->id === $preferred ? 0 : 1)->values();
        }

        return [$branches, []];
    }

    /**
     * @param  array<string, int>  $units  variant id => quantity
     * @param  Collection<string, ProductVariant>  $variants
     * @param  array<string, bool>  $assembly  the variants the customer wants assembled
     * @return list<array{variant_id: string, product_id: string, quantity: int, lead_days: int, assembly: bool}>
     */
    private function parcelLines(array $units, Collection $variants, array $assembly, string $type): array
    {
        $out = [];
        foreach ($units as $variantId => $qty) {
            $product = $variants[$variantId]->product;
            $out[] = ['variant_id' => (string) $variantId, 'product_id' => $product->id, 'quantity' => (int) $qty, 'lead_days' => (int) $product->lead_time_days,
                'assembly' => $type === 'delivery' && ($assembly[$variantId] ?? false) && Assembly::offered($product)];
        }

        return $out;
    }
}
