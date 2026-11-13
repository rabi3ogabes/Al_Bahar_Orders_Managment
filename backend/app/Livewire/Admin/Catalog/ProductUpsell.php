<?php

namespace App\Livewire\Admin\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\UpsellService;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "Upsell products" tab: pick up to 8 products suggested next to this one ("Complete the set"). Every change is saved
 * at once (like the gallery), removing offers Undo, and the optional mutual link adds this product to the other one too.
 */
class ProductUpsell extends Component
{
    use InteractsWithAdmin;

    public Product $product;

    public string $search = '';

    /** Also suggest this product on the product being added (off by default; applies to the next add). */
    public bool $mutual = false;

    public function mount(Product $product): void
    {
        abort_unless($this->can('catalog.view'), 403);
        $this->product = $product;
    }

    public function add(string $id, UpsellService $upsells): void
    {
        abort_unless($this->canGlobally('catalog.edit'), 403);
        if ($this->attempt(fn () => $upsells->add($this->product, $id, $this->mutual), __('catalog_admin.upsell_added'))) {
            $this->search = '';
        }
    }

    public function remove(string $id, UpsellService $upsells): void
    {
        abort_unless($this->canGlobally('catalog.edit'), 403);
        $position = $upsells->remove($this->product, $id);
        if ($position !== null) {
            $this->dispatch('toast', type: 'success', message: __('catalog_admin.upsell_removed'), undoLabel: __('admin.undo'),
                undoEvent: 'upsell-undo', undoParams: ['productId' => $this->product->id, 'id' => $id, 'position' => $position]);
        }
    }

    /** Undo toast handler: puts the removed product back where it was. */
    #[On('upsell-undo')]
    public function undo(string $productId, string $id, int $position, UpsellService $upsells): void
    {
        abort_unless($this->canGlobally('catalog.edit'), 403);
        if ($productId === $this->product->id) {
            $this->attempt(fn () => $upsells->add($this->product, $id, false, $position), __('catalog_admin.upsell_restored'));
        }
    }

    public function move(string $id, int $dir, UpsellService $upsells): void
    {
        abort_unless($this->canGlobally('catalog.edit'), 403);
        $upsells->move($this->product, $id, $dir);
    }

    public function render(UpsellService $upsells)
    {
        $chosen = $upsells->chosen($this->product);
        $results = $upsells->candidates($this->product, $this->search);

        return view('livewire.admin.catalog.product-upsell', [
            'chosen' => $chosen,
            'results' => $results,
            'stock' => $upsells->stockFor($chosen->concat($results)),
            'suggestedBy' => $upsells->suggestedByCount($this->product),
            'max' => UpsellService::MAX,
            'canEdit' => $this->canGlobally('catalog.edit'),
        ]);
    }
}
