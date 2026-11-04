<?php

namespace App\Livewire\Admin\Inventory;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\PurchaseOrder;
use App\Domain\Inventory\Models\Supplier;
use App\Domain\Inventory\Services\PurchasingService;
use App\Livewire\Admin\Concerns\WithAdminTable;
use App\Livewire\Admin\Concerns\WithVariantPicker;
use App\Support\Money;
use Livewire\Attributes\Url;
use Livewire\Component;

class PurchaseOrderIndex extends Component
{
    use WithAdminTable, WithVariantPicker;

    #[Url(except: '')]
    public string $filterStatus = '';

    #[Url(except: '')]
    public string $filterSupplier = '';

    public bool $showEditor = false;

    public array $form = [];

    public array $lines = [];

    public function mount(): void
    {
        abort_unless($this->can('purchasing.view'), 403);
    }

    public function create(): void
    {
        abort_unless($this->can('purchasing.create'), 403);
        $branches = $this->scopedBranches('purchasing.create');
        $this->form = ['supplier_id' => $this->filterSupplier, 'branch_id' => $branches->count() === 1 ? $branches->first()->id : '', 'expected_at' => '', 'supplier_reference' => '', 'notes' => ''];
        $this->lines = [];
        $this->resetValidation();
        $this->showEditor = true;
    }

    protected function onVariantPicked(ProductVariant $variant): void
    {
        foreach ($this->lines as $l) {
            if ($l['variant_id'] === $variant->id) {
                $this->toast(__('inventory_admin.already_added'), 'warning');

                return;
            }
        }
        $cost = $variant->cost_price ?? $variant->product()->value('cost_price');
        $this->lines[] = ['variant_id' => $variant->id, 'quantity' => 1, 'unit_cost' => $cost !== null ? rtrim(rtrim((string) $cost, '0'), '.') : ''];
    }

    public function removeLine(int $i): void
    {
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
    }

    public function save(PurchasingService $svc)
    {
        $d = $this->validate([
            'form.supplier_id' => 'required|uuid|exists:suppliers,id',
            'form.branch_id' => 'required|uuid|exists:branches,id',
            'form.expected_at' => 'nullable|date',
            'form.supplier_reference' => 'nullable|string|max:120',
            'form.notes' => 'nullable|string|max:2000',
            'lines' => 'required|array|min:1|max:300',
            'lines.*.variant_id' => 'required|uuid|exists:product_variants,id|distinct',
            'lines.*.quantity' => 'required|integer|min:1|max:1000000',
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:99999999', 'regex:/^\d+(\.\d{1,4})?$/'],
        ], ['lines.required' => __('inventory_admin.add_a_line')], ['form.supplier_id' => __('inventory_admin.supplier'), 'form.branch_id' => __('inventory_admin.receiving_branch'),
            'lines.*.quantity' => __('admin.quantity'), 'lines.*.unit_cost' => __('inventory_admin.unit_cost')]);
        $this->authorizeBranch('purchasing.create', $d['form']['branch_id']);
        $f = $d['form'];
        $po = $this->attempt(fn () => $svc->create(auth()->user(), $f['supplier_id'], $f['branch_id'],
            array_map(fn ($l) => ['variant_id' => $l['variant_id'], 'quantity' => (int) $l['quantity'], 'unit_cost' => (string) $l['unit_cost']], $d['lines']),
            ['expected_at' => $f['expected_at'] ?: null, 'notes' => $f['notes'] ?: null, 'supplier_reference' => $f['supplier_reference'] ?: null]));
        if ($po instanceof PurchaseOrder) {
            session()->flash('toast', ['type' => 'success', 'message' => __('inventory_admin.po_created')]);

            return $this->redirectRoute('admin.purchase-orders.show', $po, navigate: true);
        }
    }

    public function render()
    {
        $q = PurchaseOrder::query()->with(['supplier:id,code,name', 'branch:id,code,name_en,name_ar'])->withCount('items')
            ->when($this->filterStatus === 'open', fn ($q) => $q->whereIn('status', ['draft', 'pending_approval', 'approved', 'partially_received']), fn ($q) => $q->when($this->filterStatus, fn ($q, $s) => $q->where('status', $s)))
            ->when($this->filterSupplier, fn ($q, $s) => $q->where('supplier_id', $s))
            ->when($this->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'ilike', "%{$s}%")->orWhere('supplier_reference', 'ilike', "%{$s}%")))
            ->latest();
        $this->branchScope('purchasing.view')->apply($q->getQuery());
        $total = Money::zero();
        foreach ($this->lines as $l) {
            if (is_numeric($l['unit_cost'] ?? null) && is_numeric($l['quantity'] ?? null) && preg_match('/^\d+(\.\d{1,4})?$/', (string) $l['unit_cost'])) {
                $total = $total->plus(Money::of((string) $l['unit_cost'])->times((int) $l['quantity']));
            }
        }

        return view('livewire.admin.inventory.purchase-orders', [
            'orders' => $q->paginate($this->perPage),
            'suppliers' => Supplier::where('status', 'active')->orderBy('name')->get(['id', 'code', 'name']),
            'branches' => $this->scopedBranches('purchasing.create'),
            'lineVariants' => $this->variantsFor(array_column($this->lines, 'variant_id')),
            'matches' => $this->variantMatches(),
            'draftTotal' => $total,
        ])->title(__('nav.purchase_orders'));
    }
}
