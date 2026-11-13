<?php

namespace App\Livewire\Admin\Catalog;

use App\Domain\Catalog\Contracts\ProductSearch;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Checkout\Services\StaffOrderService;
use App\Livewire\Admin\Concerns\WithAdminTable;
use Livewire\Attributes\Url;
use Livewire\Component;

class ProductIndex extends Component
{
    use WithAdminTable;

    #[Url(except: '')]
    public string $filterStatus = '';

    #[Url(except: '')]
    public string $filterCategory = '';

    #[Url(except: '')]
    public string $filterBrand = '';

    #[Url(except: '')]
    public string $filterFlag = '';

    /** @var list<string> */
    public array $selected = [];

    public array $bulk = ['status' => '', 'category_id' => '', 'brand_id' => ''];

    public function mount(): void
    {
        abort_unless($this->can('catalog.view'), 403);
        if ($this->sort === '') {
            $this->sort = 'updated';
        }
    }

    public function updatedPaginators(): void
    {
        $this->selected = [];
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'filterStatus', 'filterCategory', 'filterBrand', 'filterFlag', 'selected');
        $this->resetPage();
    }

    public function applyBulk(ProductService $products): void
    {
        abort_unless($this->canGlobally('catalog.edit'), 403);
        $data = $this->validate([
            'selected' => 'required|array|min:1|max:500', 'selected.*' => 'uuid',
            'bulk.status' => 'nullable|in:draft,active,inactive,archived',
            'bulk.category_id' => 'nullable|uuid|exists:categories,id',
            'bulk.brand_id' => 'nullable|uuid|exists:brands,id',
        ]);
        if (! array_filter($data['bulk'])) {
            $this->toast(__('catalog_admin.bulk_choose_change'), 'warning');

            return;
        }
        $n = $this->attempt(fn () => $products->bulkUpdate($data['selected'], $data['bulk']));
        if ($n !== null) {
            $this->toast(trans_choice('catalog_admin.bulk_done', $n, ['count' => $n]));
            $this->reset('selected', 'bulk');
        }
    }

    public function duplicate(string $id, ProductService $products)
    {
        abort_unless($this->canGlobally('catalog.create'), 403);
        $copy = $this->attempt(fn () => $products->duplicate(Product::findOrFail($id)));
        if ($copy instanceof Product) {
            session()->flash('toast', ['type' => 'success', 'message' => __('catalog_admin.duplicated')]);

            return $this->redirectRoute('admin.products.edit', $copy, navigate: true);
        }
    }

    public function archive(string $id, ProductService $products): void
    {
        abort_unless($this->canGlobally('catalog.delete'), 403);
        $this->attempt(fn () => $products->archive(Product::findOrFail($id)), __('catalog_admin.archived'));
    }

    /** One-tap "Add to order": 1 unit of a single-variant product to the staff member's current order draft. */
    public function addToOrder(string $id, StaffOrderService $orders)
    {
        $user = auth()->user();
        abort_unless($orders->canCreate($user), 403);
        $variants = Product::findOrFail($id)->variants()->where('status', 'active')->get();
        if ($variants->count() !== 1) {
            return $this->redirectRoute('admin.orders.create', ['search' => Product::findOrFail($id)->sku], navigate: true); // several options: choose on the New order page
        }
        $draft = $this->attempt(fn () => $orders->ensureCurrent($user));
        if (! $draft || ! $this->attempt(fn () => $orders->add($user, $draft, $variants->first()->id, 1))) {
            return null;
        }
        $this->dispatch('order-cart-updated');
        $this->dispatch('toast', type: 'success', message: __('neworder_admin.added'), undoLabel: __('neworder_admin.undo'), undoEvent: 'order-cart-unadd',
            undoParams: ['variantId' => $variants->first()->id, 'quantity' => 1]);
        if (! $draft->user_id) {
            $this->dispatch('open-order-cart'); // no customer yet: the panel asks for one
        }

        return null;
    }

    public function render(ProductSearch $search)
    {
        $q = Product::query()->with(['translations', 'brand:id,name_en,name_ar', 'category.translations', 'images' => fn ($i) => $i->whereNull('product_variant_id')])
            ->withCount('variants')
            ->when($this->filterStatus, fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->where('status', '!=', 'archived'))
            ->when($this->filterCategory, function ($q, $c) {
                $cat = Category::find($c);
                $q->whereIn('category_id', $cat ? $cat->descendantAndSelfIds() : [$c]);
            })
            ->when($this->filterBrand, fn ($q, $b) => $q->where('brand_id', $b))
            ->when(in_array($this->filterFlag, ['is_featured', 'is_new_arrival', 'is_bestseller', 'is_oversized', 'requires_assembly'], true), fn ($q) => $q->where($this->filterFlag, true));
        if (trim($this->search) !== '') {
            $search->apply($q, $this->search, $this->sort === 'relevance');
        }
        match ($this->sort) {
            'sku' => $q->orderBy('sku'),
            'price_asc' => $q->orderBy('price'),
            'price_desc' => $q->orderByDesc('price'),
            'relevance' => null,
            default => $q->orderByDesc('updated_at'),
        };

        return view('livewire.admin.catalog.products', [
            'products' => $q->paginate($this->perPage),
            'categories' => CategoryIndex::treeOptions(),
            'brands' => Brand::orderBy('name_en')->get(['id', 'name_en', 'name_ar']),
            'canCreate' => $this->canGlobally('catalog.create'),
            'canEdit' => $this->canGlobally('catalog.edit'),
            'canDelete' => $this->canGlobally('catalog.delete'),
            'canOrder' => app(StaffOrderService::class)->canCreate(auth()->user()),
            'hasFilters' => (bool) ($this->search || $this->filterStatus || $this->filterCategory || $this->filterBrand || $this->filterFlag),
        ])->title(__('nav.products'));
    }
}
