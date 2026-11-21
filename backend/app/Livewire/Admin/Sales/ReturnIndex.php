<?php

namespace App\Livewire\Admin\Sales;

use App\Domain\Checkout\Support\Assembly;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\ReturnRequest;
use App\Domain\Orders\Services\ReturnService;
use App\Domain\Payments\Models\Refund;
use App\Livewire\Admin\Concerns\WithAdminTable;
use App\Livewire\Admin\Sales\Concerns\PeeksAtOrder;
use App\Support\Money;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Return and damage claims: start the review → approve (choosing how the goods come back) or reject (a reply is required) → receive (restock or
 * write off) → raise the refund through the normal refund flow → close. A row opens the claim in a side window (`?view=<claim id>`) with the
 * customer's photos (private, zoomable), their note and what they added later, the order lines and the claim's own log; the order opens in a
 * second window on top.
 */
class ReturnIndex extends Component
{
    use PeeksAtOrder, WithAdminTable;

    #[Url(except: 'open')]
    public string $filterStatus = 'open';

    #[Locked]
    public ?string $selectedId = null;

    public bool $showDetail = false;

    /** Mirrors the open window in the address bar so reload, sharing and Back work. */
    #[Locked]
    #[Url(as: 'view', except: '')]
    public string $viewId = '';

    public string $note = '';

    /** How the goods come back when approving: pickup | branch | none. */
    public string $method = 'branch';

    public string $methodNote = '';

    /** What happens to the goods when they arrive: restock | write_off. */
    public string $stockChoice = 'restock';

    public string $refundAmount = '';

    public string $refundMethod = 'original';

    public function mount(): void
    {
        abort_unless($this->can('orders.view'), 403);
        if ($this->viewId !== '') {
            $this->select($this->viewId, quiet: true);
        }
    }

    /** @param bool $quiet true for a deep link on page load: a return the viewer may not see simply does not open. */
    public function select(string $id, bool $quiet = false): void
    {
        $rr = Str::isUuid($id) ? ReturnRequest::find($id) : null;
        if ($quiet && (! $rr || ! $this->can('orders.view', $rr->branch_id))) {
            $this->viewId = '';

            return;
        }
        $rr ??= ReturnRequest::findOrFail($id);
        $this->authorizeBranch('orders.view', $rr->branch_id);
        $this->viewId = $rr->id;
        $this->selectedId = $rr->id;
        $this->reset('note', 'methodNote');
        $this->method = $rr->reason_code === 'missing_item' ? 'none' : 'branch';
        // A claim about the condition of the goods never goes back on the shelf by itself: the manager has to choose it.
        $this->stockChoice = $rr->isDefect() ? 'write_off' : 'restock';
        $this->refundAmount = $this->suggestedRefund($rr)->toDecimal();
        $this->refundMethod = 'original';
        $this->resetValidation();
        $this->showDetail = true;
    }

    public function closeDetail(): void
    {
        $this->showDetail = false;
        $this->viewId = '';
    }

    private function suggestedRefund(ReturnRequest $rr): Money
    {
        $items = OrderItem::whereIn('id', collect($rr->items)->pluck('order_item_id'))->get()->keyBy('id');
        $total = Money::zero();
        foreach ($rr->items as $line) {
            $i = $items[$line['order_item_id']] ?? null;
            if ($i && $i->quantity > 0) {
                $units = (int) $line['quantity'];
                // The goods, and the assembly the customer paid for those same units (they are not built, so they are not owed).
                $total = $total->plus(Money::ofMinor(intdiv(Money::of($i->line_total)->minor * $units, $i->quantity)))->plus(Assembly::refundShare($i, $units));
            }
        }

        return $total;
    }

    private function selected(): ReturnRequest
    {
        return ReturnRequest::findOrFail((string) $this->selectedId);
    }

    public function startReview(ReturnService $svc): void
    {
        $rr = $this->selected();
        $this->authorizeBranch('orders.edit', $rr->branch_id);
        $this->attempt(fn () => $svc->startReview($rr, auth()->user()), __('returns_admin.review_started'));
    }

    public function decide(bool $approve, ReturnService $svc): void
    {
        $rr = $this->selected();
        $this->authorizeBranch('orders.approve', $rr->branch_id);
        if (! $approve) {
            $this->validate(['note' => 'required|string|min:3|max:500'], [], ['note' => __('returns_admin.reply')]);
        } else {
            $this->validate(['note' => 'nullable|string|max:500', 'method' => 'required|in:'.implode(',', ReturnService::METHODS), 'methodNote' => 'nullable|string|max:300'], [], ['note' => __('returns_admin.reply')]);
        }
        $this->attempt(fn () => $svc->decide($rr, auth()->user(), $approve, trim($this->note) ?: null, $approve ? $this->method : null, trim($this->methodNote) ?: null),
            $approve ? __('sales_admin.return_approved') : __('sales_admin.return_rejected'));
    }

    public function receive(ReturnService $svc): void
    {
        $rr = $this->selected();
        $this->authorizeBranch('orders.edit', $rr->branch_id);
        $this->validate(['stockChoice' => 'required|in:restock,write_off']);
        $this->attempt(fn () => $svc->receive($rr, auth()->user(), $this->stockChoice === 'restock'), __('sales_admin.return_received'));
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
            $this->closeDetail();
        }
    }

    public function render()
    {
        $scope = $this->branchScope('orders.view');
        $s = trim($this->search);
        $base = fn () => tap(ReturnRequest::query(), fn ($q) => $scope->apply($q->getQuery(), 'branch_id'));
        $q = $base()->with(['order:id,number,customer_name,payment_method', 'user:id,name'])->withCount('photos')
            ->when($this->filterStatus === 'open', fn ($q) => $q->whereIn('status', ['requested', 'under_review', 'approved', 'received']), fn ($q) => $q->when($this->filterStatus, fn ($q, $st) => $q->where('status', $st)))
            ->when($s, fn ($q) => $q->where(fn ($w) => $w->where('number', 'ilike', "%{$s}%")->orWhereHas('order', fn ($o) => $o->where('number', 'ilike', "%{$s}%"))))
            ->latest();

        $sel = $this->selectedId ? ReturnRequest::with(['order', 'user:id,name', 'photos', 'events', 'branch'])->find($this->selectedId) : null;
        $items = $sel ? OrderItem::whereIn('id', collect($sel->items)->pluck('order_item_id'))->get()->keyBy('id') : collect();

        return view('livewire.admin.sales.returns', [
            'returns' => $q->paginate($this->perPage),
            'pending' => $base()->whereIn('status', ReturnRequest::UNDECIDED)->count(),
            'sel' => $sel,
            'items' => $items,
            'actors' => $sel ? User::whereIn('id', $sel->events->pluck('actor_id')->filter()->unique())->pluck('name', 'id') : collect(),
            'refund' => $sel?->refund_id ? Refund::find($sel->refund_id) : null,
            'branches' => $this->scopedBranches('orders.view'),
        ])->title(__('nav.returns'));
    }
}
