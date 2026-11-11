<?php

namespace App\Livewire\Admin\Inventory;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Inventory\Models\BranchInventory;
use App\Livewire\Admin\Concerns\WithAdminTable;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Stock by branch (on hand / reserved / available), low & out-of-stock views, thresholds and valuation. */
class StockIndex extends Component
{
    use WithAdminTable;

    #[Url(except: '')]
    public string $filterLevel = '';

    public bool $showEditor = false;

    public array $edit = [];

    public function mount(): void
    {
        abort_unless($this->can('inventory.view'), 403);
    }

    public function openThresholds(string $id): void
    {
        $row = BranchInventory::with(['variant.product.translations', 'branch:id,code,name_en,name_ar'])->findOrFail($id);
        $this->authorizeBranch('inventory.edit', $row->branch_id);
        $this->resetValidation();
        $this->edit = ['id' => $row->id, 'minimum_stock' => $row->minimum_stock, 'maximum_stock' => $row->maximum_stock ?? '', 'bin_location' => (string) $row->bin_location];
        $this->showEditor = true;
    }

    public function saveThresholds(AuditLogger $audit): void
    {
        $row = BranchInventory::findOrFail($this->edit['id'] ?? null);
        $this->authorizeBranch('inventory.edit', $row->branch_id);
        $data = $this->validate([
            'edit.minimum_stock' => 'required|integer|min:0|max:1000000',
            'edit.maximum_stock' => 'nullable|integer|gte:edit.minimum_stock|max:1000000',
            'edit.bin_location' => 'nullable|string|max:64',
        ], [], ['edit.minimum_stock' => __('inventory_admin.min_stock'), 'edit.maximum_stock' => __('inventory_admin.max_stock')])['edit'];
        $row->fill(['minimum_stock' => (int) $data['minimum_stock'], 'maximum_stock' => $data['maximum_stock'] === '' || $data['maximum_stock'] === null ? null : (int) $data['maximum_stock'],
            'bin_location' => $data['bin_location'] ?: null]);
        $dirty = $row->getDirty();
        $old = array_intersect_key($row->getOriginal(), $dirty);
        $row->save(); // thresholds only — quantities are never written here
        if ($dirty) {
            $audit->log('inventory.thresholds_updated', $row, $old, $dirty);
        }
        $this->showEditor = false;
        $this->toast(__('admin.saved'));
    }

    public function render()
    {
        $scope = $this->branchScope('inventory.view');
        $s = trim($this->search);
        $q = BranchInventory::query()->with(['branch:id,code,name_en,name_ar', 'variant.product.translations', 'variant.attributeValues'])
            ->when($s !== '', fn ($q) => $q->whereHas('variant', fn ($v) => $v->where('sku', 'ilike', '%'.addcslashes($s, '%_\\').'%')->orWhere('barcode', $s)
                ->orWhereHas('product.translations', fn ($t) => $t->where('name', 'ilike', '%'.addcslashes($s, '%_\\').'%'))))
            ->when($this->filterLevel === 'low', fn ($q) => $q->where('minimum_stock', '>', 0)->whereRaw('on_hand - reserved <= minimum_stock')->whereRaw('on_hand - reserved > 0'))
            ->when($this->filterLevel === 'out', fn ($q) => $q->whereRaw('on_hand - reserved <= 0'))
            ->when($this->filterLevel === 'reserved', fn ($q) => $q->where('reserved', '>', 0))
            ->when($this->filterLevel === 'over', fn ($q) => $q->whereNotNull('maximum_stock')->whereColumn('on_hand', '>', 'maximum_stock'));
        $scope->apply($q->getQuery());
        match ($this->sort) {
            'available_desc' => $q->orderByRaw('on_hand - reserved desc'),
            'recent' => $q->orderByDesc('last_movement_at'),
            default => $q->orderByRaw('on_hand - reserved asc'),
        };

        // Valuation and health per branch (one aggregate query, within scope).
        $agg = DB::table('branch_inventory');
        $scope->apply($agg);
        $summary = $agg->groupBy('branch_id')->selectRaw('branch_id, sum(on_hand) as units, sum(reserved) as reserved,
                round(sum(on_hand * coalesce(average_cost, 0)), 2) as value,
                sum(case when on_hand - reserved <= 0 then 1 else 0 end) as out_count,
                sum(case when minimum_stock > 0 and on_hand - reserved <= minimum_stock and on_hand - reserved > 0 then 1 else 0 end) as low_count')
            ->get()->keyBy('branch_id');
        $branches = Branch::whereIn('id', $summary->keys())->orderBy('sort_order')->get(['id', 'code', 'name_en', 'name_ar']);
        $money = fn ($v) => Money::of((string) ($v ?? '0'));

        return view('livewire.admin.inventory.stock', [
            'rows' => $q->paginate($this->perPage),
            'summary' => $summary,
            'summaryBranches' => $branches,
            'totalValue' => $summary->reduce(fn ($c, $r) => $c->plus($money($r->value)), Money::zero()),
            'money' => $money,
            'totals' => ['units' => (int) $summary->sum('units'), 'reserved' => (int) $summary->sum('reserved'), 'low' => (int) $summary->sum('low_count'), 'out' => (int) $summary->sum('out_count')],
            'branches' => $this->scopedBranches('inventory.view'),
            'editable' => app(AccessControl::class)->scope(auth()->user(), 'inventory.edit'),
        ])->title(__('nav.stock'));
    }
}
