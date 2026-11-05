<?php

namespace App\Livewire\Admin\Sales;

use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\ReturnRequest;
use App\Domain\Orders\Services\ReturnService;
use App\Domain\Payments\Models\Refund;
use App\Livewire\Admin\Concerns\WithAdminTable;
use App\Support\Money;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Return requests queue and decision flow: approve/reject → receive (restock) → raise refund → close. */
class ReturnIndex extends Component
{
    use WithAdminTable;

    #[Url(except: 'open')]
    public string $filterStatus = 'open';

    #[Locked]
    public ?string $selectedId = null;

    public bool $showDetail = false;

    public string $note = '';

    public bool $restock = true;

    public string $refundAmount = '';

    public string $refundMethod = 'original';

    public function mount(): void
    {
        abort_unless($this->can('orders.view'), 403);
    }

    public function select(string $id): void
    {
        $rr = ReturnRequest::findOrFail($id);
        $this->authorizeBranch('orders.view', $rr->branch_id);
        $this->selectedId = $rr->id;
        $this->reset('note');
        $this->restock = true;
        $this->refundAmount = $this->suggestedRefund($rr)->toDecimal();
        $this->refundMethod = 'original';
        $this->resetValidation();
        $this->showDetail = true;
    }

    private function suggestedRefund(ReturnRequest $rr): Money
    {
        $items = OrderItem::whereIn('id', collect($rr->items)->pluck('order_item_id'))->get()->keyBy('id');
        $total = Money::zero();
        foreach ($rr->items as $line) {
            $i = $items[$line['order_item_id']] ?? null;
            if ($i && $i->quantity > 0) {
                $total = $total->plus(Money::ofMinor(intdiv(Money::of($i->line_total)->minor * (int) $line['quantity'], $i->quantity)));
            }
        }

        return $total;
    }

    private function selected(): ReturnRequest
    {
        return ReturnRequest::findOrFail((string) $this->selectedId);
    }

    public function decide(bool $approve, ReturnService $svc): void
    {
        $rr = $this->selected();
        $this->authorizeBranch('orders.approve', $rr->branch_id);
        if (! $approve) {
            $this->validate(['note' => 'required|string|min:3|max:500'], [], ['note' => __('admin.reason')]);
        }
        $this->attempt(fn () => $svc->decide($rr, auth()->user(), $approve, trim($this->note) ?: null), $approve ? __('sales_admin.return_approved') : __('sales_admin.return_rejected'));
    }

    public function receive(ReturnService $svc): void
    {
        $rr = $this->selected();
        $this->authorizeBranch('orders.edit', $rr->branch_id);
        $this->attempt(fn () => $svc->receive($rr, auth()->user(), $this->restock), __('sales_admin.return_received'));
    }

    public function refund(ReturnService $svc): void
    {
        $rr = $this->selected();
        $this->authorizeBranch('refunds.create', $rr->branch_id);
        $this->validate(['refundAmount' => 'required|numeric|min:0.01', 'refundMethod' => 'required|in:original,manual_cash,manual_bank_transfer'], [], ['refundAmount' => __('finance_admin.amount')]);
        $this->attempt(fn () => $svc->refund($rr, auth()->user(), Money::of((string) $this->refundAmount), $this->refundMethod), __('sales_admin.return_refund_raised'));
    }

    public function close(ReturnService $svc): void
    {
        $rr = $this->selected();
        $this->authorizeBranch('orders.approve', $rr->branch_id);
        if ($this->attempt(fn () => $svc->close($rr, auth()->user()), __('sales_admin.return_closed'))) {
            $this->showDetail = false;
        }
    }

    public function render()
    {
        $scope = $this->branchScope('orders.view');
        $s = trim($this->search);
        $base = fn () => tap(ReturnRequest::query(), fn ($q) => $scope->apply($q->getQuery(), 'branch_id'));
        $q = $base()->with(['order:id,number,customer_name,payment_method', 'user:id,name'])
            ->when($this->filterStatus === 'open', fn ($q) => $q->whereIn('status', ['requested', 'approved', 'received']), fn ($q) => $q->when($this->filterStatus, fn ($q, $st) => $q->where('status', $st)))
            ->when($s, fn ($q) => $q->where(fn ($w) => $w->where('number', 'ilike', "%{$s}%")->orWhereHas('order', fn ($o) => $o->where('number', 'ilike', "%{$s}%"))))
            ->latest();

        $sel = $this->selectedId ? ReturnRequest::with(['order', 'user:id,name'])->find($this->selectedId) : null;
        $items = $sel ? OrderItem::whereIn('id', collect($sel->items)->pluck('order_item_id'))->get()->keyBy('id') : collect();

        return view('livewire.admin.sales.returns', [
            'returns' => $q->paginate($this->perPage),
            'pending' => $base()->where('status', 'requested')->count(),
            'sel' => $sel,
            'items' => $items,
            'refund' => $sel?->refund_id ? Refund::find($sel->refund_id) : null,
            'branches' => $this->scopedBranches('orders.view'),
        ])->title(__('nav.returns'));
    }
}
