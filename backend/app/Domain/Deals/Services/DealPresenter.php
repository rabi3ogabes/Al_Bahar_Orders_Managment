<?php

namespace App\Domain\Deals\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\CatalogPresenter;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\DealProduct;
use App\Domain\Deals\Support\DealDesign;
use App\Domain\Deals\Support\DealPricing;
use App\Domain\Identity\Models\User;
use App\Support\BusinessTime;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The public face of deals (docs/api/DEALS.md section 5): the list of live and upcoming deals and one deal with its products as catalog cards.
 * Every response carries `server_time`, so a client counts down from `ends_at` using the server's clock, not only the phone's.
 */
class DealPresenter
{
    /** Products per page on one deal. */
    public const PAGE_SIZE = 24;

    public function __construct(private readonly CatalogPresenter $catalog) {}

    public function serverTime(): string
    {
        return (string) BusinessTime::iso(now());
    }

    /**
     * `GET /deals`: the deals people can act on now (`live`, the one ending soonest first; a sold-out one stays for 15 minutes) and the ones that start
     * later (`upcoming`). Paused and ended deals are never listed. Cards for all deals are built together: the number of queries does not grow with the deals.
     *
     * @return array{server_time: string, live: list<array<string, mixed>>, upcoming: list<array<string, mixed>>}
     */
    public function index(?User $user, ?Branch $branch, bool $homeOnly, int $limitProducts): array
    {
        $now = now();
        $deals = Deal::query()->open($now)->when($homeOnly, fn ($q) => $q->where('show_on_home', true))
            ->with(['products' => fn ($q) => $q->select('id', 'deal_id', 'product_id', 'quantity_total', 'quantity_sold', 'sort_order')->with('product:id,status')])->get();

        $live = [];
        $upcoming = [];
        foreach ($deals as $deal) {
            $phase = $deal->phase($now);
            $visible = $deal->products->filter(fn (DealProduct $p) => $p->product?->status === 'active');
            if ($visible->isEmpty()) {
                continue;
            }
            if ($phase === Deal::LIVE || ($phase === Deal::SOLD_OUT && DealService::soldOutStillVisible($deal))) {
                $live[] = [$deal, $visible];
            } elseif ($phase === Deal::SCHEDULED) {
                $upcoming[] = [$deal, $visible];
            }
        }
        usort($live, fn (array $a, array $b) => [$a[0]->ends_at->getTimestamp(), $a[0]->sort_order, $a[0]->id] <=> [$b[0]->ends_at->getTimestamp(), $b[0]->sort_order, $b[0]->id]);
        usort($upcoming, fn (array $a, array $b) => [$a[0]->starts_at?->getTimestamp(), $a[0]->sort_order, $a[0]->id] <=> [$b[0]->starts_at?->getTimestamp(), $b[0]->sort_order, $b[0]->id]);

        // One catalog query + one set of cards for every product shown on this page.
        $shown = [];
        foreach (array_merge($live, $upcoming) as [$deal, $visible]) {
            $shown[$deal->id] = $visible->take($limitProducts)->pluck('product_id')->all();
        }
        $cards = $this->cardsFor(collect($shown)->flatten()->unique()->values()->all(), $user, $branch);
        $brand = DealDesign::brandPrimary();
        $present = fn (array $pair) => $this->deal($pair[0], $pair[1]->count(), array_values(array_filter(array_map(fn (string $id) => $cards[$id] ?? null, $shown[$pair[0]->id]))), $brand, $now);

        return ['server_time' => $this->serverTime(), 'live' => array_map($present, $live), 'upcoming' => array_map($present, $upcoming)];
    }

    /**
     * `GET /deals/{id}`: one deal with a page of its products (24 a page) as the same cards the catalog uses, each with its `deal` block.
     *
     * @return array<string, mixed>
     */
    public function show(Deal $deal, ?User $user, ?Branch $branch, int $page): array
    {
        $deal->unsetRelation('products');
        $rows = DealProduct::query()->where('deal_id', $deal->id)->whereHas('product', fn ($q) => $q->active()->visibleInBranch($branch?->id))
            ->orderBy('sort_order')->orderBy('created_at')->orderBy('id')->paginate(self::PAGE_SIZE, ['*'], 'page', max(1, $page));
        $ids = $rows->getCollection()->pluck('product_id')->all();
        $cards = $this->cardsFor($ids, $user, $branch);
        $out = $this->deal($deal, $rows->total(), array_values(array_filter(array_map(fn (string $id) => $cards[$id] ?? null, $ids))), DealDesign::brandPrimary(), now());
        $out['products'] = ['data' => $out['products'], 'meta' => ['current_page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(), 'last_page' => $rows->lastPage()]];

        return ['server_time' => $this->serverTime()] + $out;
    }

    /**
     * The `Deal` shape. `$products` are cards already built; `$total` how many products the deal has in all.
     *
     * @param  list<array<string, mixed>>  $products
     * @return array<string, mixed>
     */
    public function deal(Deal $deal, int $total, array $products, ?string $brand = null, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $perCustomer = $deal->limit_per_customer;

        return [
            'id' => $deal->id, 'name' => $deal->localized('name'), 'tagline' => $deal->localized('tagline'),
            'phase' => $deal->phase($now),
            'starts_at' => BusinessTime::iso($deal->starts_at), 'ends_at' => BusinessTime::iso($deal->ends_at),
            // Seconds to the end (what the countdown shows) and, for a deal that has not started, seconds to its start: the client adds its own offset to the server clock.
            'seconds_left' => max(0, (int) $now->diffInSeconds($deal->ends_at, false)),
            'seconds_to_start' => $deal->starts_at !== null && $deal->starts_at->greaterThan($now) ? (int) $now->diffInSeconds($deal->starts_at, false) : null,
            'discount' => ['type' => $deal->discount_type, 'value' => $deal->discount_type === Deal::FIXED_PRICE ? null : DealPricing::plain($deal->discount_value)],
            'limits' => ['per_order' => $deal->limit_per_order, 'per_customer' => $perCustomer],
            'products_total' => $total,
            'products' => $products,
            'design' => DealDesign::resolve($deal->design, $brand),
            'show_on_home' => $deal->show_on_home,
        ];
    }

    /**
     * Catalog cards for these products, keyed by product id (inactive products and ones not sold at the branch simply do not appear).
     *
     * @param  list<string>  $productIds
     * @return array<string, array<string, mixed>>
     */
    private function cardsFor(array $productIds, ?User $user, ?Branch $branch): array
    {
        if ($productIds === []) {
            return [];
        }
        /** @var Collection<int, Product> $products */
        $products = Product::query()->active()->visibleInBranch($branch?->id)->whereIn('id', $productIds)
            ->with(['translations', 'brand', 'images', 'variants' => fn ($v) => $v->where('status', 'active')])->get();

        $cards = [];
        foreach ($this->catalog->cards($products, $branch, $user) as $card) {
            $cards[$card['id']] = $card;
        }

        return $cards;
    }
}
