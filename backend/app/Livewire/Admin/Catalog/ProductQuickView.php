<?php

namespace App\Livewire\Admin\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Checkout\Services\StaffOrderService;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Inventory\Models\BranchInventory;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The side window of the products list: photo, names, price, stock in the viewer's branches and the switches that are safe to flip on the spot
 * (visible in store, featured, new arrival, bestseller). Every switch saves at once through ProductService (audited) and shows the "Saved" toast;
 * the full editor stays a page and is one tap away.
 */
class ProductQuickView extends Component
{
    use InteractsWithAdmin;

    /** The switches this window may flip. Everything else is edited in the full editor. */
    public const FLAGS = ['is_featured', 'is_new_arrival', 'is_bestseller'];

    #[Locked]
    public string $productId = '';

    /** @var array<string, bool> */
    public array $flags = [];

    public bool $visible = false;

    public function mount(string $productId): void
    {
        abort_unless($this->can('catalog.view'), 403);
        $this->productId = $productId;
        $this->syncFromProduct(Product::findOrFail($productId));
    }

    private function product(): Product
    {
        return Product::findOrFail($this->productId);
    }

    private function syncFromProduct(Product $product): void
    {
        $this->flags = collect(self::FLAGS)->mapWithKeys(fn ($f) => [$f => (bool) $product->{$f}])->all();
        $this->visible = $product->status === 'active';
    }

    /** wire:model.live on a flag: save at once; on failure the switch goes back to what is stored. */
    public function updatedFlags(mixed $value, string $flag, ProductService $products): void
    {
        abort_unless($this->canGlobally('catalog.edit'), 403);
        abort_unless(in_array($flag, self::FLAGS, true), 422);
        $product = $this->product();
        $saved = $this->attempt(fn () => $products->update($product, [$flag => (bool) $value]), __('admin.saved'));
        $this->syncFromProduct($product->refresh());
        if ($saved) {
            $this->dispatch('product-changed');
        }
    }

    /** "Visible in store": active ⇄ inactive (a draft becomes active = published; archived products are restored in the full editor). */
    public function updatedVisible(mixed $value, ProductService $products): void
    {
        abort_unless($this->canGlobally('catalog.edit'), 403);
        $product = $this->product();
        if ($product->status === 'archived') {
            $this->syncFromProduct($product);

            return;
        }
        $saved = $this->attempt(fn () => $products->update($product, ['status' => $value ? 'active' : 'inactive']), __('admin.saved'));
        $this->syncFromProduct($product->refresh());
        if ($saved) {
            $this->dispatch('product-changed');
        }
    }

    /** Units per branch, only for branches the viewer may see stock of (the same scope as the Inventory page). */
    private function stockByBranch(Product $product): array
    {
        $acl = app(AccessControl::class);
        if (! $acl->allows(auth()->user(), 'inventory.view')) {
            return ['rows' => collect(), 'visible' => false];
        }
        $scope = $acl->scope(auth()->user(), 'inventory.view');
        $rows = BranchInventory::query()->with('branch:id,code,name_en,name_ar')
            ->join('product_variants as v', 'v.id', '=', 'branch_inventory.product_variant_id')
            ->where('v.product_id', $product->id)->where('v.status', 'active')
            ->select('branch_inventory.*')->limit(500);
        $scope->apply($rows->getQuery(), 'branch_inventory.branch_id');

        return [
            'visible' => true,
            'rows' => $rows->get()->groupBy('branch_id')->map(fn ($g) => [
                'branch' => $g->first()->branch,
                'available' => max(0, $g->sum(fn ($r) => $r->on_hand - $r->reserved)),
                'low' => $g->contains(fn ($r) => $r->isLow()),
            ])->sortBy(fn ($r) => $r['branch']?->code)->values(),
        ];
    }

    public function render()
    {
        $product = $this->product()->load(['translations', 'brand:id,name_en,name_ar', 'category.translations', 'images' => fn ($i) => $i->whereNull('product_variant_id')->limit(1)]);

        return view('livewire.admin.catalog.product-quick-view', [
            'product' => $product,
            'stock' => $this->stockByBranch($product),
            'variantCount' => $product->variants()->count(),
            'upsellCount' => $product->upsells()->count(),
            'canEdit' => $this->canGlobally('catalog.edit'),
            'canOrder' => app(StaffOrderService::class)->canCreate(auth()->user()) && $product->status === 'active',
        ]);
    }
}
