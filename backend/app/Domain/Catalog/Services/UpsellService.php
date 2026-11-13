<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Contracts\ProductSearch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Orders\Models\Cart;
use App\Support\DomainException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * "Complete the set": the products an admin suggests next to a product (product_related rows of type `upsell`),
 * shown to customers on the product page and, deduplicated, in the cart. Admin writes are audited.
 */
class UpsellService
{
    public const TYPE = 'upsell';

    public const MAX = 8;

    public function __construct(private readonly CatalogPresenter $presenter, private readonly ProductSearch $search, private readonly AuditLogger $audit) {}

    // ── Admin ────────────────────────────────────────────────────────────────

    /** The suggested products in order (any status, so the admin can see what the customer will not). @return EloquentCollection<int, Product> */
    public function chosen(Product $product): EloquentCollection
    {
        return $product->upsells()->with(['translations', 'images' => fn ($i) => $i->whereNull('product_variant_id')->limit(1)])->get();
    }

    /** How many other products suggest this one. */
    public function suggestedByCount(Product $product): int
    {
        return DB::table('product_related')->where('related_product_id', $product->id)->where('type', self::TYPE)->count();
    }

    /** Live search for the picker: published products, never itself or one already chosen. @return EloquentCollection<int, Product> */
    public function candidates(Product $product, string $term, int $limit = 8): EloquentCollection
    {
        $term = trim($term);
        if ($term === '') {
            return new EloquentCollection;
        }
        $q = Product::query()->active()->where('products.id', '!=', $product->id)
            ->whereNotIn('products.id', fn ($s) => $s->select('related_product_id')->from('product_related')->where('product_id', $product->id)->where('type', self::TYPE))
            ->with(['translations', 'images' => fn ($i) => $i->whereNull('product_variant_id')->limit(1)]);
        $this->search->apply($q, $term);

        return $q->limit($limit)->get();
    }

    /** @return array<string, int> product id → units available across active branches */
    public function stockFor(iterable $products): array
    {
        $ids = collect($products)->pluck('id')->all();
        if (! $ids) {
            return [];
        }

        return DB::table('branch_inventory as bi')->join('product_variants as v', 'v.id', '=', 'bi.product_variant_id')
            ->join('branches as b', 'b.id', '=', 'bi.branch_id')->where('b.status', 'active')->where('v.status', 'active')->whereIn('v.product_id', $ids)
            ->selectRaw('v.product_id, sum(bi.on_hand - bi.reserved) as available')->groupBy('v.product_id')
            ->pluck('available', 'v.product_id')->map(fn ($n) => max(0, (int) $n))->all();
    }

    public function add(Product $product, string $relatedId, bool $mutual = false, ?int $position = null): void
    {
        $related = Product::find($relatedId);
        if ($relatedId === $product->id || ! $related || ! $related->isActive()) {
            throw DomainException::make('upsell_invalid');
        }
        DB::transaction(function () use ($product, $related, $mutual, $position) {
            $this->link($product, $related, $position);
            if ($mutual) {
                $this->link($related, $product, null, silent: true);
            }
        });
        $this->audit->log('catalog.upsell_added', $product, [], ['related_product_id' => $related->id, 'sku' => $related->sku, 'mutual' => $mutual]);
    }

    public function remove(Product $product, string $relatedId): ?int
    {
        $position = null;
        DB::transaction(function () use ($product, $relatedId, &$position) {
            $ids = $this->orderedIds($product, lock: true);
            $index = array_search($relatedId, $ids, true);
            if ($index === false) {
                return;
            }
            $position = $index;
            DB::table('product_related')->where('product_id', $product->id)->where('related_product_id', $relatedId)->where('type', self::TYPE)->delete();
            $this->resequence($product, array_values(array_diff($ids, [$relatedId])));
        });
        if ($position !== null) {
            $this->audit->log('catalog.upsell_removed', $product, ['related_product_id' => $relatedId], []);
        }

        return $position;
    }

    public function move(Product $product, string $relatedId, int $direction): void
    {
        DB::transaction(function () use ($product, $relatedId, $direction) {
            $ids = $this->orderedIds($product, lock: true);
            $i = array_search($relatedId, $ids, true);
            $j = $i === false ? false : $i + ($direction < 0 ? -1 : 1);
            if ($i === false || ! isset($ids[$j])) {
                return;
            }
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            $this->resequence($product, $ids);
            $this->audit->log('catalog.upsell_reordered', $product, [], ['order' => $ids]);
        });
    }

    // ── Customer API ─────────────────────────────────────────────────────────

    /** Cards for the product page: published, purchasable at the branch and in stock, in the admin's order. */
    public function forProduct(Product $product, ?Branch $branch): array
    {
        $ids = $this->orderedIds($product);

        return $this->cardsFor($ids, $branch);
    }

    /** Cards for the cart: upsells of what is in the cart, minus what is already there, strongest first, at most MAX. */
    public function forCart(Cart $cart, ?Branch $branch): array
    {
        $inCart = DB::table('cart_items as ci')->join('product_variants as v', 'v.id', '=', 'ci.product_variant_id')
            ->where('ci.cart_id', $cart->id)->distinct()->pluck('v.product_id')->all();
        if (! $inCart) {
            return [];
        }
        $ids = DB::table('product_related')->where('type', self::TYPE)->whereIn('product_id', $inCart)->whereNotIn('related_product_id', $inCart)
            ->selectRaw('related_product_id, min(sort_order) as pos, count(*) as votes')->groupBy('related_product_id')
            ->orderByDesc('votes')->orderBy('pos')->orderBy('related_product_id')->limit(self::MAX * 2)->pluck('related_product_id')->all();

        return $this->cardsFor($ids, $branch);
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /** @param list<string> $ids ordered product ids */
    private function cardsFor(array $ids, ?Branch $branch): array
    {
        if (! $ids) {
            return [];
        }
        $products = Product::active()->whereIn('id', $ids)
            ->with(['translations', 'brand', 'images', 'variants' => fn ($v) => $v->where('status', 'active')])->get()->keyBy('id');
        $ordered = collect($ids)->map(fn ($id) => $products->get($id))->filter()->values();

        return collect($this->presenter->cards($ordered, $branch))->filter(fn ($c) => $c['purchasable'] && $c['in_stock'])->take(self::MAX)->values()->all();
    }

    /** @return list<string> */
    private function orderedIds(Product $product, bool $lock = false): array
    {
        $q = DB::table('product_related')->where('product_id', $product->id)->where('type', self::TYPE)->orderBy('sort_order')->orderBy('related_product_id');
        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->pluck('related_product_id')->all();
    }

    /** @param list<string> $ids */
    private function resequence(Product $product, array $ids): void
    {
        foreach ($ids as $i => $id) {
            DB::table('product_related')->where('product_id', $product->id)->where('related_product_id', $id)->where('type', self::TYPE)->update(['sort_order' => $i]);
        }
    }

    private function link(Product $from, Product $to, ?int $position, bool $silent = false): void
    {
        $ids = $this->orderedIds($from, lock: true);
        if (in_array($to->id, $ids, true)) {
            if ($silent) {
                return;
            }
            throw DomainException::make('upsell_invalid');
        }
        if (count($ids) >= self::MAX) {
            if ($silent) {
                return; // the other product is full; the one-way link the admin asked for still stands
            }
            throw DomainException::make('upsell_limit', 422, ['max' => self::MAX]);
        }
        DB::table('product_related')->insert(['product_id' => $from->id, 'related_product_id' => $to->id, 'type' => self::TYPE, 'sort_order' => count($ids)]);
        if ($position !== null && $position < count($ids)) {
            array_splice($ids, max(0, $position), 0, [$to->id]);
            $this->resequence($from, $ids);
        }
    }
}
