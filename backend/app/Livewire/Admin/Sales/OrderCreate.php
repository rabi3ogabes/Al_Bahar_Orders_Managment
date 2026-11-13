<?php

namespace App\Livewire\Admin\Sales;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Contracts\ProductSearch;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\CatalogPresenter;
use App\Domain\Checkout\Services\StaffOrderService;
use App\Domain\Pricing\Services\PriceResolver;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/** "New order": product browser on the left, the docked order cart panel on the right (a bottom sheet on phones). */
class OrderCreate extends Component
{
    use InteractsWithAdmin;

    private const PAGE = 24;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: 'cart')]
    public string $step = 'cart';

    public int $limit = self::PAGE;

    public function mount(): void
    {
        abort_unless(app(StaffOrderService::class)->canCreate(auth()->user()), 403);
        $this->step = $this->step === 'checkout' ? 'checkout' : 'cart';
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'category'], true)) {
            $this->limit = self::PAGE;
        }
    }

    public function more(): void
    {
        $this->limit += self::PAGE;
    }

    #[On('order-cart-updated')]
    public function refresh(): void
    {
        // re-render only (stock badges and the "in cart" markers)
    }

    public function add(string $variantId, int $quantity = 1): void
    {
        $user = auth()->user();
        $svc = app(StaffOrderService::class);
        $quantity = max(1, min($quantity, (int) config('platform.checkout.max_quantity_per_line')));
        $ok = $this->attempt(fn () => $svc->add($user, $svc->ensureCurrent($user), $variantId, $quantity));
        if ($ok) {
            $this->dispatch('order-cart-updated');
            $this->dispatch('toast', type: 'success', message: __('neworder_admin.added'), undoLabel: __('neworder_admin.undo'), undoEvent: 'order-cart-unadd',
                undoParams: ['variantId' => $variantId, 'quantity' => $quantity]);
        }
    }

    public function render(PriceResolver $prices, CatalogPresenter $presenter, ProductSearch $searcher)
    {
        $user = auth()->user();
        $svc = app(StaffOrderService::class);
        $draft = $svc->current($user);
        $scope = $svc->scope($user);
        $branchId = $draft?->branch_id ?? ($scope->all ? null : ($scope->branchIds[0] ?? null));
        $branch = $branchId ? Branch::active()->find($branchId) : null;

        $q = Product::query()->active()->with(['translations', 'images' => fn ($i) => $i->whereNull('product_variant_id')->limit(1),
            'variants' => fn ($v) => $v->where('status', 'active')->orderByDesc('is_default')->with('attributeValues')]);
        if ($this->category !== '' && ($cat = Category::find($this->category))) {
            $q->whereIn('category_id', $cat->descendantAndSelfIds());
        }
        if (trim($this->search) !== '') {
            $searcher->apply($q, $this->search);
        } else {
            $q->orderByDesc('is_bestseller')->orderByDesc('updated_at');
        }
        $found = $q->limit($this->limit + 1)->get();
        $hasMore = $found->count() > $this->limit;
        $products = $found->take($this->limit);

        $variants = $products->flatMap->variants;
        $prices->prime($variants, $branch?->id);
        $stock = $presenter->stockMap($variants->pluck('id')->all(), $branch?->id);
        $inCart = $draft ? $draft->items()->pluck('quantity', 'product_variant_id') : collect();

        $cards = $products->map(function (Product $p) use ($prices, $branch, $stock, $inCart) {
            $rows = $p->variants->map(function ($v) use ($p, $prices, $branch, $stock, $inCart) {
                $v->setRelation('product', $p);
                $price = $prices->resolve($v, $branch);
                $available = max(0, $stock[$v->id] ?? 0);

                return ['id' => $v->id, 'name' => $v->displayName(), 'sku' => $v->sku, 'price' => $price?->final(), 'regular' => $price && $price->sale ? $price->regular : null,
                    'stock' => $available, 'ok' => $price !== null && $available > 0, 'in_cart' => (int) ($inCart[$v->id] ?? 0)];
            });
            $okRows = $rows->where('ok', true);
            $from = $rows->filter(fn ($r) => $r['price'])->sortBy(fn ($r) => (float) $r['price']->toDecimal())->first();

            return ['product' => $p, 'variants' => $rows, 'multi' => $rows->count() > 1, 'from' => $from['price'] ?? null, 'stock' => $rows->sum('stock'),
                'orderable' => $okRows->isNotEmpty(), 'in_cart' => $rows->sum('in_cart'), 'image' => $p->images->first()];
        });

        return view('livewire.admin.sales.order-create', [
            'cards' => $cards, 'hasMore' => $hasMore, 'branch' => $branch, 'draft' => $draft, 'units' => $svc->count($user),
            'categories' => Category::with('translations')->whereNull('parent_id')->orderBy('sort_order')->limit(16)->get(),
        ])->title(__('neworder_admin.page_title'));
    }
}
