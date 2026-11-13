<?php

namespace App\Livewire\Admin\Inventory;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\BranchInventory;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Services\TransferService;
use App\Livewire\Admin\Concerns\WithAdminTable;
use App\Livewire\Admin\Concerns\WithRecordView;
use App\Livewire\Admin\Concerns\WithVariantPicker;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

class TransferIndex extends Component
{
    use WithAdminTable, WithRecordView, WithVariantPicker;

    #[Url(except: '')]
    public string $filterStatus = '';

    #[Url(except: '')]
    public string $direction = '';

    public bool $showEditor = false;

    public array $form = ['source_branch_id' => '', 'destination_branch_id' => '', 'notes' => '', 'submit' => true];

    public array $lines = [];

    public function mount(): void
    {
        abort_unless($this->can('transfers.view'), 403);
    }

    /** Same rule as the transfer page: transfers.view at the source or the destination branch. */
    protected function viewable(string $id): bool
    {
        $t = StockTransfer::find($id, ['id', 'source_branch_id', 'destination_branch_id']);

        return $t !== null && ($this->can('transfers.view', $t->source_branch_id) || $this->can('transfers.view', $t->destination_branch_id));
    }

    /** The side window changed a transfer (approve, dispatch, receive…): re-render so the list row is current. */
    #[On('inventory-doc-changed')]
    public function refreshAfterChange(): void {}

    public function create(): void
    {
        abort_unless($this->can('transfers.create'), 403);
        $this->resetValidation();
        $sources = $this->scopedBranches('transfers.create');
        $this->form = ['source_branch_id' => $sources->count() === 1 ? $sources->first()->id : '', 'destination_branch_id' => '', 'notes' => '', 'submit' => true];
        $this->lines = [];
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
        $this->lines[] = ['variant_id' => $variant->id, 'quantity' => 1];
    }

    public function removeLine(int $i): void
    {
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
    }

    public function save(TransferService $svc)
    {
        $data = $this->validate([
            'form.source_branch_id' => 'required|uuid|exists:branches,id',
            'form.destination_branch_id' => 'required|uuid|exists:branches,id|different:form.source_branch_id',
            'form.notes' => 'nullable|string|max:1000',
            'form.submit' => 'boolean',
            'lines' => 'required|array|min:1|max:200',
            'lines.*.variant_id' => 'required|uuid|exists:product_variants,id',
            'lines.*.quantity' => 'required|integer|min:1|max:100000',
        ], ['lines.required' => __('inventory_admin.add_a_line')], ['form.source_branch_id' => __('inventory_admin.from_branch'), 'form.destination_branch_id' => __('inventory_admin.to_branch'), 'lines.*.quantity' => __('admin.quantity')]);
        $this->authorizeBranch('transfers.create', $data['form']['source_branch_id']);
        $f = $data['form'];
        $transfer = $this->attempt(function () use ($svc, $f, $data) {
            $t = $svc->create(auth()->user(), $f['source_branch_id'], $f['destination_branch_id'], $data['lines'], $f['notes'] ?: null);

            return $f['submit'] ? $svc->submit(auth()->user(), $t) : $t;
        });
        if ($transfer instanceof StockTransfer) {
            $this->showEditor = false;
            $this->toast(__('inventory_admin.transfer_created'));
            $this->openView($transfer->id); // the new transfer opens beside the list
        }
    }

    public function render()
    {
        $scope = $this->branchScope('transfers.view');
        $q = StockTransfer::query()->with(['source:id,code,name_en,name_ar', 'destination:id,code,name_en,name_ar'])->withCount('items')
            ->withSum('items as units', 'quantity_requested')
            ->when($this->filterStatus === 'open', fn ($q) => $q->whereNotIn('status', ['completed', 'rejected', 'cancelled']), fn ($q) => $q->when($this->filterStatus, fn ($q, $s) => $q->where('status', $s)))
            ->when($this->search, fn ($q, $s) => $q->where('number', 'ilike', "%{$s}%"))
            ->latest();
        if (! $scope->all) {
            $ids = $scope->branchIds ?: ['00000000-0000-0000-0000-000000000000'];
            $q->where(fn ($w) => match ($this->direction) {
                'outgoing' => $w->whereIn('source_branch_id', $ids),
                'incoming' => $w->whereIn('destination_branch_id', $ids),
                default => $w->whereIn('source_branch_id', $ids)->orWhereIn('destination_branch_id', $ids),
            });
        }
        $sourceId = $this->form['source_branch_id'] ?? null;
        $available = $sourceId && $this->lines
            ? BranchInventory::where('branch_id', $sourceId)->whereIn('product_variant_id', array_column($this->lines, 'variant_id'))->get()->mapWithKeys(fn ($r) => [$r->product_variant_id => $r->available()])
            : collect();

        return view('livewire.admin.inventory.transfers', [
            'transfers' => $q->paginate($this->perPage),
            'sources' => $this->scopedBranches('transfers.create'),
            'destinations' => Branch::active()->orderBy('sort_order')->get(['id', 'code', 'name_en', 'name_ar']),
            'lineVariants' => $this->variantsFor(array_column($this->lines, 'variant_id')),
            'available' => $available,
            'matches' => $this->variantMatches(),
            'scopeAll' => $scope->all,
            'viewTransfer' => ($id = $this->viewing()) ? StockTransfer::with(['source:id,code,name_en,name_ar', 'destination:id,code,name_en,name_ar'])->find($id) : null,
        ])->title(__('nav.transfers'));
    }
}
