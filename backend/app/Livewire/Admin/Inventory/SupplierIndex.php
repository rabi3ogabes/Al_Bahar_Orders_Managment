<?php

namespace App\Livewire\Admin\Inventory;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Inventory\Models\PurchaseOrder;
use App\Domain\Inventory\Models\Supplier;
use App\Livewire\Admin\Concerns\WithAdminTable;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Suppliers are shared across branches; anyone holding suppliers.* at any branch may manage them. */
class SupplierIndex extends Component
{
    use WithAdminTable;

    #[Url(except: '')]
    public string $filterStatus = '';

    public bool $showEditor = false;

    public ?string $editingId = null;

    public array $form = [];

    public function mount(): void
    {
        abort_unless($this->can('suppliers.view'), 403);
        $this->form = $this->blank();
    }

    private function blank(): array
    {
        return ['code' => '', 'name' => '', 'contact_name' => '', 'phone' => '', 'email' => '', 'address' => '', 'country' => 'QA', 'tax_number' => '',
            'payment_terms' => '', 'currency' => 'QAR', 'status' => 'active', 'notes' => ''];
    }

    public function create(): void
    {
        abort_unless($this->can('suppliers.create'), 403);
        $this->resetValidation();
        $this->editingId = null;
        $this->form = $this->blank();
        $this->showEditor = true;
    }

    public function edit(string $id): void
    {
        abort_unless($this->can('suppliers.edit'), 403);
        $s = Supplier::findOrFail($id);
        $this->resetValidation();
        $this->editingId = $s->id;
        $this->form = array_map(fn ($v) => (string) $v, $s->only(array_keys($this->blank())));
        $this->showEditor = true;
    }

    public function save(AuditLogger $audit): void
    {
        abort_unless($this->can($this->editingId ? 'suppliers.edit' : 'suppliers.create'), 403);
        $this->form['code'] = strtoupper(trim((string) ($this->form['code'] ?? ''))); // codes are stored upper-case: check uniqueness on that form
        $data = $this->validate([
            'form.code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9\-_]+$/', Rule::unique('suppliers', 'code')->ignore($this->editingId)],
            'form.name' => 'required|string|max:191',
            'form.contact_name' => 'nullable|string|max:120',
            'form.phone' => 'nullable|string|max:32',
            'form.email' => 'nullable|email|max:191',
            'form.address' => 'nullable|string|max:255',
            'form.country' => 'nullable|string|size:2|alpha',
            'form.tax_number' => 'nullable|string|max:64',
            'form.payment_terms' => 'nullable|string|max:120',
            'form.currency' => 'required|string|size:3|alpha',
            'form.status' => 'required|in:active,inactive',
            'form.notes' => 'nullable|string|max:2000',
        ], [], ['form.code' => __('org_admin.code'), 'form.name' => __('admin.name')])['form'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $data['code'] = strtoupper($data['code']);
        $data['country'] = $data['country'] ? strtoupper($data['country']) : null;
        $data['currency'] = strtoupper($data['currency']);
        $s = $this->editingId ? Supplier::findOrFail($this->editingId) : new Supplier;
        $s->fill($data);
        $dirty = $s->getDirty();
        $old = array_intersect_key($s->getOriginal(), $dirty);
        $s->save();
        $audit->log($this->editingId ? 'purchasing.supplier_updated' : 'purchasing.supplier_created', $s, $old, $dirty);
        $this->showEditor = false;
        $this->toast(__('admin.saved'));
    }

    public function delete(string $id, AuditLogger $audit): void
    {
        abort_unless($this->can('suppliers.delete'), 403);
        $s = Supplier::findOrFail($id);
        if (PurchaseOrder::where('supplier_id', $s->id)->exists()) {
            $this->toast(__('inventory_admin.supplier_in_use'), 'error');

            return;
        }
        $snapshot = $s->only(['id', 'code', 'name']);
        $s->delete();
        $audit->log('purchasing.supplier_deleted', 'Supplier', $snapshot);
        $this->toast(__('inventory_admin.supplier_deleted'));
    }

    public function render()
    {
        $s = $this->search;
        $suppliers = Supplier::query()
            ->selectSub(fn ($q) => $q->from('purchase_orders')->selectRaw('count(*)')->whereColumn('purchase_orders.supplier_id', 'suppliers.id')->whereIn('status', ['draft', 'pending_approval', 'approved', 'partially_received']), 'open_po_count')
            ->addSelect('suppliers.*')
            ->when($s, fn ($q) => $q->where(fn ($w) => $w->where('name', 'ilike', "%{$s}%")->orWhere('code', 'ilike', "%{$s}%")->orWhere('contact_name', 'ilike', "%{$s}%")->orWhere('email', 'ilike', "%{$s}%")))
            ->when($this->filterStatus, fn ($q, $st) => $q->where('status', $st))
            ->orderBy('name');

        return view('livewire.admin.inventory.suppliers', [
            'suppliers' => $suppliers->paginate($this->perPage),
            'canCreate' => $this->can('suppliers.create'),
            'canEdit' => $this->can('suppliers.edit'),
            'canDelete' => $this->can('suppliers.delete'),
        ])->title(__('nav.suppliers'));
    }
}
