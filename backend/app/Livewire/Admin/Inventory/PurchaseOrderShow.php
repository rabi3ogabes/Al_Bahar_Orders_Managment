<?php

namespace App\Livewire\Admin\Inventory;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Inventory\Models\GoodsReceipt;
use App\Domain\Inventory\Models\PurchaseOrder;
use App\Domain\Inventory\Services\PurchasingService;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Support\BusinessTime;
use Livewire\Component;

/** Purchase order lifecycle and goods receiving (partial quantities, rejected quantities, supplier invoice). */
class PurchaseOrderShow extends Component
{
    use InteractsWithAdmin;

    public PurchaseOrder $po;

    /** PO item id => [received, rejected, unit_cost, rejection_reason] */
    public array $receipt = [];

    public string $invoice = '';

    public string $receiptNotes = '';

    public function mount(PurchaseOrder $po): void
    {
        abort_unless($this->can('purchasing.view', $po->branch_id), 403);
        $this->po = $po;
        $this->prefill();
    }

    private function prefill(): void
    {
        $this->po->load('items');
        $this->receipt = [];
        foreach ($this->po->items as $i) {
            $this->receipt[$i->id] = ['received' => max(0, $i->quantity_ordered - $i->quantity_received), 'rejected' => 0,
                'unit_cost' => rtrim(rtrim((string) $i->unit_cost, '0'), '.'), 'rejection_reason' => ''];
        }
        $this->reset('invoice', 'receiptNotes');
    }

    private function run(callable $fn, string $msg): void
    {
        if ($this->attempt($fn, $msg)) {
            $this->po->refresh();
            $this->prefill();
        }
    }

    public function submit(PurchasingService $svc): void
    {
        $this->authorizeBranch('purchasing.create', $this->po->branch_id);
        $this->run(fn () => $svc->submit(auth()->user(), $this->po), __('inventory_admin.po_submitted'));
    }

    public function approve(PurchasingService $svc): void
    {
        $this->authorizeBranch('purchasing.approve', $this->po->branch_id);
        $this->run(fn () => $svc->approve(auth()->user(), $this->po), __('inventory_admin.po_approved'));
    }

    public function reject(PurchasingService $svc): void
    {
        $this->authorizeBranch('purchasing.approve', $this->po->branch_id);
        $this->run(fn () => $svc->reject(auth()->user(), $this->po), __('inventory_admin.po_rejected'));
    }

    public function close(PurchasingService $svc): void
    {
        $this->authorizeBranch('purchasing.approve', $this->po->branch_id);
        $this->run(fn () => $svc->close(auth()->user(), $this->po), __('inventory_admin.po_closed'));
    }

    public function cancel(PurchasingService $svc): void
    {
        $this->authorizeBranch('purchasing.approve', $this->po->branch_id);
        $this->run(fn () => $svc->cancel(auth()->user(), $this->po), __('inventory_admin.po_cancelled'));
    }

    public function receive(PurchasingService $svc): void
    {
        $this->authorizeBranch('inventory.create', $this->po->branch_id);
        $this->validate([
            'receipt' => 'array',
            'receipt.*.received' => 'required|integer|min:0|max:1000000',
            'receipt.*.rejected' => 'required|integer|min:0|max:1000000',
            'receipt.*.unit_cost' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/'],
            'receipt.*.rejection_reason' => 'nullable|string|max:255',
            'invoice' => 'nullable|string|max:120',
            'receiptNotes' => 'nullable|string|max:2000',
        ], [], ['receipt.*.received' => __('inventory_admin.received'), 'receipt.*.rejected' => __('inventory_admin.rejected_qty'), 'receipt.*.unit_cost' => __('inventory_admin.unit_cost')]);
        foreach ($this->receipt as $id => $r) {
            if ((int) $r['rejected'] > 0 && trim((string) $r['rejection_reason']) === '') {
                $this->addError("receipt.{$id}.rejection_reason", __('inventory_admin.rejection_reason_required'));

                return;
            }
        }
        $lines = collect($this->receipt)->filter(fn ($r) => (int) $r['received'] + (int) $r['rejected'] > 0)
            ->map(fn ($r) => ['received' => (int) $r['received'], 'rejected' => (int) $r['rejected'], 'unit_cost' => (string) $r['unit_cost'], 'rejection_reason' => $r['rejection_reason'] ?: null])->all();
        if (! $lines) {
            $this->toast(__('inventory_admin.nothing_to_receive'), 'warning');

            return;
        }
        $this->run(fn () => $svc->receive(auth()->user(), $this->po, $lines, $this->invoice ?: null, $this->receiptNotes ?: null), __('inventory_admin.goods_received'));
    }

    public function render()
    {
        $po = $this->po->load(['supplier', 'branch', 'items.variant.product.translations', 'items.variant.attributeValues']);
        $receipts = GoodsReceipt::with(['items'])->where('purchase_order_id', $po->id)->latest('received_at')->get();
        $people = User::whereIn('id', collect([$po->created_by, $po->approved_by])->merge($receipts->pluck('received_by'))->filter()->unique())->pluck('name', 'id');
        $acl = app(AccessControl::class);
        $u = auth()->user();
        $s = $po->status;
        $d = fn ($v) => $v ? BusinessTime::display($v) : null;
        $steps = [
            ['label' => __('inventory_admin.step_created'), 'at' => $d($po->created_at), 'by' => $people[$po->created_by] ?? null, 'state' => 'done'],
            ['label' => __('inventory_admin.step_submitted'), 'at' => $d($po->submitted_at), 'state' => $po->submitted_at ? 'done' : ($s === 'draft' ? 'current' : 'todo')],
            ['label' => __('inventory_admin.step_approved'), 'at' => $d($po->approved_at), 'by' => $people[$po->approved_by] ?? null, 'state' => $po->approved_at ? 'done' : ($s === 'pending_approval' ? 'current' : 'todo')],
            ['label' => $s === 'partially_received' ? __('inventory_admin.step_partially_received') : __('inventory_admin.step_received'), 'at' => $d($receipts->first()?->received_at),
                'state' => in_array($s, ['received', 'closed'], true) ? 'done' : (in_array($s, ['approved', 'partially_received'], true) ? 'current' : 'todo')],
            ['label' => __('inventory_admin.step_closed'), 'at' => $d($po->closed_at), 'state' => $s === 'closed' ? 'done' : ($s === 'received' ? 'current' : 'todo')],
        ];
        if (in_array($s, ['rejected', 'cancelled'], true)) {
            $steps = array_values(array_filter($steps, fn ($st) => $st['state'] === 'done'));
            $steps[] = ['label' => __('status.'.$s), 'at' => $d($po->updated_at), 'state' => 'failed'];
        }
        $isCreator = $po->created_by === $u->id && ! $u->isSuperAdmin();
        $approver = $acl->allows($u, 'purchasing.approve', $po->branch_id);

        return view('livewire.admin.inventory.purchase-order-show', [
            'po' => $po,
            'receipts' => $receipts,
            'people' => $people,
            'steps' => $steps,
            'can' => [
                'submit' => $s === 'draft' && $acl->allows($u, 'purchasing.create', $po->branch_id),
                'approve' => $s === 'pending_approval' && $approver && ! $isCreator,
                'awaitingOther' => $s === 'pending_approval' && $isCreator,
                'reject' => $s === 'pending_approval' && $approver,
                'receive' => in_array($s, ['approved', 'partially_received'], true) && $acl->allows($u, 'inventory.create', $po->branch_id),
                'close' => in_array($s, ['received', 'partially_received'], true) && $approver,
                'cancel' => in_array($s, ['draft', 'pending_approval', 'approved'], true) && $approver,
            ],
        ])->title($po->number);
    }
}
