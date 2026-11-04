<?php

namespace App\Livewire\Admin\Inventory;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Services\TransferService;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Support\BusinessTime;
use Livewire\Component;

/** Transfer workspace: approve (with quantity edits), dispatch, receive (partial, damaged, notes), complete, cancel. */
class TransferShow extends Component
{
    use InteractsWithAdmin;

    public StockTransfer $transfer;

    /** item id => qty (approve / dispatch) */
    public array $qty = [];

    /** item id => [received, damaged, note] */
    public array $receipt = [];

    public string $reason = '';

    public ?string $confirming = null; // reject | cancel

    public function mount(StockTransfer $transfer): void
    {
        $acl = app(AccessControl::class);
        abort_unless($acl->allows(auth()->user(), 'transfers.view', $transfer->source_branch_id) || $acl->allows(auth()->user(), 'transfers.view', $transfer->destination_branch_id), 403);
        $this->transfer = $transfer;
        $this->prefill();
    }

    private function prefill(): void
    {
        $this->transfer->load('items');
        $svc = app(TransferService::class);
        foreach ($this->transfer->items as $i) {
            $this->qty[$i->id] = match ($this->transfer->status) {
                'pending_approval' => $i->quantity_requested,
                'approved' => $i->quantity_approved,
                default => 0,
            };
            $this->receipt[$i->id] = ['received' => $svc->inTransit($i), 'damaged' => 0, 'note' => ''];
        }
    }

    private function run(callable $action, string $success): void
    {
        if ($this->attempt($action, $success)) {
            $this->transfer->refresh();
            $this->reset('reason', 'confirming');
            $this->prefill();
        }
    }

    private function qtyMap(): array
    {
        $this->validate(['qty' => 'array', 'qty.*' => 'required|integer|min:0|max:100000'], [], ['qty.*' => __('admin.quantity')]);

        return array_map('intval', $this->qty);
    }

    public function submit(TransferService $svc): void
    {
        $this->authorizeBranch('transfers.create', $this->transfer->source_branch_id);
        $this->run(fn () => $svc->submit(auth()->user(), $this->transfer), __('inventory_admin.transfer_submitted'));
    }

    public function approve(TransferService $svc): void
    {
        $this->authorizeBranch('transfers.approve', $this->transfer->source_branch_id);
        $q = $this->qtyMap();
        $this->run(fn () => $svc->approve(auth()->user(), $this->transfer, $q), __('inventory_admin.transfer_approved'));
    }

    public function dispatchTransfer(TransferService $svc): void
    {
        $this->authorizeBranch('transfers.edit', $this->transfer->source_branch_id);
        $q = $this->qtyMap();
        $this->run(fn () => $svc->dispatch(auth()->user(), $this->transfer, $q), __('inventory_admin.transfer_dispatched'));
    }

    public function receive(TransferService $svc): void
    {
        $this->authorizeBranch('transfers.edit', $this->transfer->destination_branch_id);
        $this->validate([
            'receipt' => 'array', 'receipt.*.received' => 'required|integer|min:0|max:100000',
            'receipt.*.damaged' => 'required|integer|min:0|max:100000', 'receipt.*.note' => 'nullable|string|max:500',
        ], [], ['receipt.*.received' => __('inventory_admin.received'), 'receipt.*.damaged' => __('inventory_admin.damaged')]);
        $lines = collect($this->receipt)->filter(fn ($r) => (int) $r['received'] + (int) $r['damaged'] > 0 || trim((string) $r['note']) !== '')
            ->map(fn ($r) => ['received' => (int) $r['received'], 'damaged' => (int) $r['damaged'], 'note' => trim((string) $r['note'])])->all();
        if (! $lines) {
            $this->toast(__('inventory_admin.nothing_to_receive'), 'warning');

            return;
        }
        $this->run(fn () => $svc->receive(auth()->user(), $this->transfer, $lines), __('inventory_admin.transfer_received'));
    }

    public function complete(TransferService $svc): void
    {
        $this->authorizeBranch('transfers.approve', $this->transfer->destination_branch_id);
        $this->run(fn () => $svc->complete(auth()->user(), $this->transfer), __('inventory_admin.transfer_completed'));
    }

    public function confirmReason(TransferService $svc): void
    {
        abort_unless(in_array($this->confirming, ['reject', 'cancel'], true), 422);
        $this->authorizeBranch('transfers.approve', $this->transfer->source_branch_id);
        $this->validate(['reason' => 'required|string|min:3|max:500'], [], ['reason' => __('admin.reason')]);
        $this->confirming === 'reject'
            ? $this->run(fn () => $svc->reject(auth()->user(), $this->transfer, $this->reason), __('inventory_admin.transfer_rejected'))
            : $this->run(fn () => $svc->cancel(auth()->user(), $this->transfer, $this->reason), __('inventory_admin.transfer_cancelled'));
    }

    public function render(TransferService $svc)
    {
        $t = $this->transfer->load(['source', 'destination', 'items.variant.product.translations', 'items.variant.attributeValues']);
        $people = User::whereIn('id', array_filter([$t->requested_by, $t->approved_by, $t->dispatched_by, $t->received_by]))->pluck('name', 'id');
        $acl = app(AccessControl::class);
        $u = auth()->user();
        $at = fn (string $perm, string $branch) => $acl->allows($u, $perm, $branch);
        $s = $t->status;
        $isRequester = $t->requested_by === $u->id && ! $u->isSuperAdmin();
        $d = fn ($v) => $v ? BusinessTime::display($v) : null;
        $steps = [
            ['label' => __('inventory_admin.step_created'), 'at' => $d($t->created_at), 'by' => $people[$t->requested_by] ?? null, 'state' => 'done'],
            ['label' => __('inventory_admin.step_submitted'), 'at' => $d($t->submitted_at), 'state' => $t->submitted_at ? 'done' : ($s === 'draft' ? 'current' : 'todo')],
            ['label' => __('inventory_admin.step_approved'), 'at' => $d($t->approved_at), 'by' => $people[$t->approved_by] ?? null, 'state' => $t->approved_at ? 'done' : ($s === 'pending_approval' ? 'current' : 'todo')],
            ['label' => __('inventory_admin.step_dispatched'), 'at' => $d($t->dispatched_at), 'by' => $people[$t->dispatched_by] ?? null, 'state' => $t->dispatched_at ? 'done' : ($s === 'approved' ? 'current' : 'todo')],
            ['label' => $s === 'partially_received' ? __('inventory_admin.step_partially_received') : __('inventory_admin.step_received'), 'at' => $d($t->received_at), 'by' => $people[$t->received_by] ?? null,
                'state' => in_array($s, ['received', 'completed'], true) ? 'done' : (in_array($s, ['in_transit', 'partially_received'], true) ? 'current' : 'todo')],
            ['label' => __('inventory_admin.step_completed'), 'at' => $d($t->completed_at), 'state' => $s === 'completed' ? 'done' : ($s === 'received' ? 'current' : 'todo')],
        ];
        if (in_array($s, ['rejected', 'cancelled'], true)) {
            $steps = array_values(array_filter($steps, fn ($st) => $st['state'] === 'done'));
            $steps[] = ['label' => __('status.'.$s).($t->cancellation_reason ? ' — '.$t->cancellation_reason : ''), 'at' => $d($t->cancelled_at), 'state' => 'failed'];
        }

        return view('livewire.admin.inventory.transfer-show', [
            't' => $t,
            'steps' => $steps,
            'inTransit' => $t->items->mapWithKeys(fn ($i) => [$i->id => $svc->inTransit($i)]),
            'can' => [
                'submit' => $s === 'draft' && $at('transfers.create', $t->source_branch_id),
                'approve' => $s === 'pending_approval' && $at('transfers.approve', $t->source_branch_id) && ! $isRequester,
                'awaitingOther' => $s === 'pending_approval' && $isRequester,
                'reject' => $s === 'pending_approval' && $at('transfers.approve', $t->source_branch_id),
                'dispatch' => $s === 'approved' && $at('transfers.edit', $t->source_branch_id),
                'receive' => in_array($s, ['in_transit', 'partially_received'], true) && $at('transfers.edit', $t->destination_branch_id),
                'complete' => in_array($s, ['received', 'partially_received'], true) && $at('transfers.approve', $t->destination_branch_id),
                'cancel' => in_array($s, ['draft', 'pending_approval', 'approved'], true) && $at('transfers.approve', $t->source_branch_id),
            ],
        ])->title($t->number);
    }
}
