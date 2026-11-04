<?php

namespace App\Livewire\Admin\Inventory;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Inventory\Models\InventoryAdjustment;
use App\Domain\Inventory\Services\AdjustmentService;
use App\Livewire\Admin\Concerns\WithAdminTable;
use App\Livewire\Admin\Concerns\WithVariantPicker;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Manual stock changes with reason codes. Requests are posted to the ledger only when a second person with
 * inventory.approve at that branch approves them (AdjustmentService enforces maker ≠ checker).
 */
class AdjustmentIndex extends Component
{
    use WithAdminTable, WithVariantPicker;

    public const TYPES = ['adjustment', 'damaged', 'lost', 'opening_balance', 'customer_return'];

    #[Url(except: 'pending')]
    public string $view = 'pending';

    public bool $showEditor = false;

    public array $form = ['branch_id' => '', 'type' => 'adjustment', 'reason' => ''];

    /** @var list<array{variant_id: string, direction: string, quantity: int|string, unit_cost: string, note: string}> */
    public array $lines = [];

    public ?string $expanded = null;

    public ?string $rejecting = null;

    public string $rejectReason = '';

    public function mount(): void
    {
        abort_unless($this->can('inventory.create') || $this->can('inventory.approve'), 403);
    }

    public function create(): void
    {
        abort_unless($this->can('inventory.create'), 403);
        $this->resetValidation();
        $branches = $this->scopedBranches('inventory.create');
        $this->form = ['branch_id' => $branches->count() === 1 ? $branches->first()->id : '', 'type' => 'adjustment', 'reason' => ''];
        $this->lines = [];
        $this->variantSearch = '';
        $this->showEditor = true;
    }

    protected function onVariantPicked(ProductVariant $variant): void
    {
        foreach ($this->lines as $i => $l) {
            if ($l['variant_id'] === $variant->id) {
                $this->lines[$i]['quantity'] = (int) $l['quantity'] + 1;

                return;
            }
        }
        $this->lines[] = ['variant_id' => $variant->id, 'direction' => 'out', 'quantity' => 1, 'unit_cost' => '', 'note' => ''];
    }

    public function removeLine(int $i): void
    {
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
    }

    /** Sign is derived from the reason code; only plain adjustments let the user choose in or out. */
    private function signed(array $line): int
    {
        $q = abs((int) $line['quantity']);

        return match ($this->form['type']) {
            'damaged', 'lost' => -$q,
            'opening_balance', 'customer_return' => $q,
            default => $line['direction'] === 'in' ? $q : -$q,
        };
    }

    public function save(AdjustmentService $svc): void
    {
        $data = $this->validate([
            'form.branch_id' => 'required|uuid|exists:branches,id',
            'form.type' => 'required|in:'.implode(',', self::TYPES),
            'form.reason' => 'required|string|min:3|max:500',
            'lines' => 'required|array|min:1|max:200',
            'lines.*.variant_id' => 'required|uuid|exists:product_variants,id',
            'lines.*.direction' => 'required|in:in,out',
            'lines.*.quantity' => 'required|integer|min:1|max:100000',
            'lines.*.unit_cost' => 'nullable|numeric|min:0|max:9999999',
            'lines.*.note' => 'nullable|string|max:255',
        ], ['lines.required' => __('inventory_admin.add_a_line')], ['form.branch_id' => __('admin.branch'), 'form.reason' => __('admin.reason'), 'lines.*.quantity' => __('admin.quantity')]);
        $this->authorizeBranch('inventory.create', $data['form']['branch_id']);
        $items = array_map(fn ($l) => ['variant_id' => $l['variant_id'], 'quantity' => $this->signed($l),
            'unit_cost' => $l['unit_cost'] !== '' && $l['unit_cost'] !== null ? (string) $l['unit_cost'] : null, 'note' => $l['note'] ?: null], $data['lines']);

        $adj = $this->attempt(fn () => $svc->create(auth()->user(), $data['form']['branch_id'], $data['form']['type'], $data['form']['reason'], $items));
        if ($adj instanceof InventoryAdjustment) {
            $this->showEditor = false;
            $this->toast($adj->status === 'posted' ? __('inventory_admin.adjustment_posted') : __('inventory_admin.adjustment_requested'));
        }
    }

    public function approve(string $id, AdjustmentService $svc): void
    {
        $adj = InventoryAdjustment::findOrFail($id);
        $this->authorizeBranch('inventory.approve', $adj->branch_id);
        $this->attempt(fn () => $svc->post(auth()->user(), $adj), __('inventory_admin.adjustment_posted'));
    }

    public function startReject(string $id): void
    {
        $this->rejecting = $id;
        $this->rejectReason = '';
        $this->resetValidation();
    }

    public function reject(AdjustmentService $svc): void
    {
        $adj = InventoryAdjustment::findOrFail($this->rejecting);
        $this->authorizeBranch('inventory.approve', $adj->branch_id);
        $this->validate(['rejectReason' => 'required|string|min:3|max:500'], [], ['rejectReason' => __('admin.reason')]);
        if ($this->attempt(fn () => $svc->reject(auth()->user(), $adj, $this->rejectReason), __('inventory_admin.adjustment_rejected'))) {
            $this->reset('rejecting', 'rejectReason');
        }
    }

    public function render()
    {
        $acl = app(AccessControl::class);
        $makerScope = $acl->scope(auth()->user(), 'inventory.create');
        $approveScope = $acl->scope(auth()->user(), 'inventory.approve');
        // Makers see their branches' requests; approvers also see requests from branches they approve for.
        $all = $makerScope->all || $approveScope->all;
        $ids = array_values(array_unique(array_merge($makerScope->branchIds, $approveScope->branchIds)));
        $scoped = function ($q) use ($all, $ids) {
            if (! $all) {
                $q->whereIn('branch_id', $ids ?: ['00000000-0000-0000-0000-000000000000']);
            }
            if ($this->branch) {
                $q->where('branch_id', ($all || in_array($this->branch, $ids, true)) ? $this->branch : '00000000-0000-0000-0000-000000000000');
            }

            return $q;
        };
        $q = $scoped(InventoryAdjustment::query())->with(['branch:id,code,name_en,name_ar', 'items.variant.product.translations', 'items.variant.attributeValues'])
            ->when($this->view === 'pending', fn ($q) => $q->where('status', 'pending_approval'))
            ->when($this->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'ilike', "%{$s}%")->orWhere('reason', 'ilike', "%{$s}%")))
            ->latest();
        $adjustments = $q->paginate($this->perPage);
        $people = User::whereIn('id', $adjustments->pluck('created_by')->merge($adjustments->pluck('approved_by'))->filter()->unique())->pluck('name', 'id');

        return view('livewire.admin.inventory.adjustments', [
            'adjustments' => $adjustments,
            'people' => $people,
            'pendingCount' => $scoped(InventoryAdjustment::query())->where('status', 'pending_approval')->count(),
            'branches' => $this->scopedBranches('inventory.create'),
            'filterBranches' => Branch::query()->when(! $all, fn ($b) => $b->whereIn('id', $ids))->orderBy('sort_order')->get(['id', 'code', 'name_en', 'name_ar']),
            'approveScope' => $approveScope,
            'lineVariants' => $this->variantsFor(array_column($this->lines, 'variant_id')),
            'matches' => $this->variantMatches(),
            'types' => self::TYPES,
        ])->title(__('nav.adjustments'));
    }
}
