<?php

namespace App\Livewire\Admin\Inventory;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Inventory\Models\StocktakingItem;
use App\Domain\Inventory\Models\StocktakingSession;
use App\Domain\Inventory\Services\StocktakingService;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Count screen built for a barcode scanner: scan → Enter → type the count → Enter → next.
 * "Add one per scan" mode lets staff scan each piece instead of typing quantities.
 */
class StocktakingShow extends Component
{
    use InteractsWithAdmin, WithPagination;

    public StocktakingSession $session;

    public string $code = '';

    public string $countQty = '';

    public ?string $activeVariant = null;

    public bool $perScan = false;

    #[Url(except: '')]
    public string $filter = '';

    #[Url(except: '')]
    public string $search = '';

    public array $counts = [];

    public function mount(StocktakingSession $session): void
    {
        abort_unless($this->can('stocktaking.view', $session->branch_id), 403);
        $this->session = $session;
    }

    public function updated($prop): void
    {
        if (in_array($prop, ['filter', 'search'], true)) {
            $this->resetPage();
        }
    }

    private function resolve(string $code): ?ProductVariant
    {
        $code = trim($code);

        return $code === '' ? null : (ProductVariant::where('sku', $code)->orWhere('barcode', $code)->first()
            ?? ProductVariant::whereRaw('upper(sku) = ?', [mb_strtoupper($code)])->first());
    }

    public function scanCode(StocktakingService $svc): void
    {
        $this->authorizeBranch('stocktaking.edit', $this->session->branch_id);
        $variant = $this->resolve($this->code);
        if (! $variant) {
            $this->toast(__('inventory_admin.variant_not_found', ['code' => $this->code]), 'warning');
            $this->code = '';
            $this->dispatch('stocktake-focus', target: 'scan');

            return;
        }
        if ($this->perScan) {
            $current = (int) StocktakingItem::where('stocktaking_session_id', $this->session->id)->where('product_variant_id', $variant->id)->value('counted_quantity');
            $this->record($svc, $variant->id, $current + 1);
            $this->code = '';
            $this->dispatch('stocktake-focus', target: 'scan');

            return;
        }
        $this->activeVariant = $variant->id;
        $existing = StocktakingItem::where('stocktaking_session_id', $this->session->id)->where('product_variant_id', $variant->id)->value('counted_quantity');
        $this->countQty = $existing !== null ? (string) $existing : '';
        $this->dispatch('stocktake-focus', target: 'qty');
    }

    public function submitCount(StocktakingService $svc): void
    {
        $this->authorizeBranch('stocktaking.edit', $this->session->branch_id);
        if (! $this->activeVariant) {
            $this->dispatch('stocktake-focus', target: 'scan');

            return;
        }
        $this->validate(['countQty' => 'required|integer|min:0|max:1000000'], [], ['countQty' => __('inventory_admin.counted')]);
        $this->record($svc, $this->activeVariant, (int) $this->countQty);
        $this->reset('code', 'countQty', 'activeVariant');
        $this->dispatch('stocktake-focus', target: 'scan');
    }

    public function setCount(string $itemId, StocktakingService $svc): void
    {
        $this->authorizeBranch('stocktaking.edit', $this->session->branch_id);
        $item = StocktakingItem::where('stocktaking_session_id', $this->session->id)->findOrFail($itemId);
        $this->validate(["counts.{$itemId}" => 'required|integer|min:0|max:1000000'], [], ["counts.{$itemId}" => __('inventory_admin.counted')]);
        $this->record($svc, $item->product_variant_id, (int) $this->counts[$itemId]);
    }

    private function record(StocktakingService $svc, string $variantId, int $qty): void
    {
        $item = $this->attempt(fn () => $svc->count(auth()->user(), $this->session, $variantId, $qty));
        if ($item instanceof StocktakingItem) {
            $this->counts[$item->id] = $item->counted_quantity;
            $this->dispatch('stocktake-counted', id: $item->id);
        }
    }

    public function submitSession(StocktakingService $svc): void
    {
        $this->authorizeBranch('stocktaking.edit', $this->session->branch_id);
        $this->attempt(fn () => $svc->submit(auth()->user(), $this->session), __('inventory_admin.session_submitted'));
        $this->session->refresh();
    }

    public function post(StocktakingService $svc): void
    {
        $this->authorizeBranch('stocktaking.approve', $this->session->branch_id);
        $this->attempt(fn () => $svc->post(auth()->user(), $this->session), __('inventory_admin.session_posted'));
        $this->session->refresh();
    }

    public function cancelSession(StocktakingService $svc): void
    {
        $this->authorizeBranch('stocktaking.approve', $this->session->branch_id);
        $this->attempt(fn () => $svc->cancel(auth()->user(), $this->session), __('inventory_admin.session_cancelled'));
        $this->session->refresh();
    }

    public function render()
    {
        $base = StocktakingItem::where('stocktaking_session_id', $this->session->id);
        $stats = (clone $base)->selectRaw('count(*) as lines, count(counted_quantity) as counted,
            coalesce(sum(case when counted_quantity is not null then counted_quantity - expected_quantity else 0 end), 0) as net,
            sum(case when counted_quantity is not null and counted_quantity <> expected_quantity then 1 else 0 end) as variances')->first();
        $s = trim($this->search);
        $items = (clone $base)->with(['variant.product.translations', 'variant.attributeValues'])
            ->when($this->filter === 'uncounted', fn ($q) => $q->whereNull('counted_quantity'))
            ->when($this->filter === 'variance', fn ($q) => $q->whereNotNull('counted_quantity')->whereColumn('counted_quantity', '!=', 'expected_quantity'))
            ->when($s !== '', fn ($q) => $q->whereHas('variant', fn ($v) => $v->where('sku', 'ilike', '%'.addcslashes($s, '%_\\').'%')->orWhere('barcode', $s)))
            ->orderByRaw('counted_at desc nulls last')->orderBy('id')
            ->paginate(25);
        foreach ($items as $it) {
            $this->counts[$it->id] ??= $it->counted_quantity;
        }
        $acl = app(AccessControl::class);
        $u = auth()->user();
        $active = $this->activeVariant ? ProductVariant::with(['product.translations', 'attributeValues'])->find($this->activeVariant) : null;
        $activeItem = $active ? (clone $base)->where('product_variant_id', $active->id)->first() : null;

        return view('livewire.admin.inventory.stocktaking-show', [
            'items' => $items,
            'stats' => $stats,
            'active' => $active,
            'activeExpected' => $activeItem?->expected_quantity,
            'canCount' => $this->session->status === 'counting' && $acl->allows($u, 'stocktaking.edit', $this->session->branch_id),
            'canApprove' => $acl->allows($u, 'stocktaking.approve', $this->session->branch_id),
            'branch' => $this->session->branch,
        ])->title($this->session->number);
    }
}
