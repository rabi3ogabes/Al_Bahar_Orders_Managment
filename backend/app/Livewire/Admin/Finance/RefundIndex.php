<?php

namespace App\Livewire\Admin\Finance;

use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\Services\RefundService;
use App\Livewire\Admin\Concerns\WithAdminTable;
use App\Livewire\Admin\Finance\Concerns\ScopesPayments;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Refund queue: requested → approved (by a different person: dual control, enforced by RefundService) →
 * processed via the gateway API or manually with a bank/cash reference when the API is unavailable.
 */
class RefundIndex extends Component
{
    use ScopesPayments, WithAdminTable;

    public const TABS = ['requested', 'to_process', 'processed', 'closed'];

    #[Url(except: 'requested')]
    public string $tab = 'requested';

    public bool $showDialog = false;

    /** reject | process */
    public ?string $dialog = null;

    #[Locked]
    public ?string $target = null;

    public string $note = '';

    public string $reference = '';

    public function mount(): void
    {
        abort_unless($this->can('refunds.view'), 403);
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'requested';
        }
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    private function refund(string $id): Refund
    {
        $r = Refund::with('payment.order')->findOrFail($id);
        abort_unless($this->scopeByOrder(Refund::query(), $this->branchScope('refunds.view'))->whereKey($r->id)->exists(), 403);

        return $r;
    }

    public function approve(string $id, RefundService $svc): void
    {
        $r = $this->refund($id);
        foreach ($r->payment->order?->branchIds() ?? [] as $b) {
            $this->authorizeBranch('refunds.approve', $b);
        }
        $this->attempt(fn () => $svc->approve($r, auth()->user()), __('finance_admin.refund_approved'));
    }

    public function open(string $dialog, string $id): void
    {
        abort_unless(in_array($dialog, ['reject', 'process'], true), 422);
        $this->refund($id);
        $this->dialog = $dialog;
        $this->target = $id;
        $this->reset('note', 'reference');
        $this->resetValidation();
        $this->showDialog = true;
    }

    public function confirmDialog(RefundService $svc): void
    {
        $r = $this->refund((string) $this->target);
        $perm = $this->dialog === 'reject' ? 'refunds.approve' : 'refunds.refund';
        foreach ($r->payment->order?->branchIds() ?? [] as $b) {
            $this->authorizeBranch($perm, $b);
        }
        if ($this->dialog === 'reject') {
            $this->validate(['note' => 'required|string|min:3|max:500'], [], ['note' => __('admin.reason')]);
            $ok = $this->attempt(fn () => $svc->reject($r, auth()->user(), trim($this->note)), __('finance_admin.refund_rejected'));
        } else {
            $this->validate(['reference' => 'required|string|min:3|max:128'], [], ['reference' => __('finance_admin.bank_reference')]);
            $ok = $this->attempt(fn () => $svc->markProcessed($r, auth()->user(), trim($this->reference)), __('finance_admin.refund_processed'));
        }
        if ($ok) {
            $this->showDialog = false;
        }
    }

    public function render()
    {
        $scope = $this->branchScope('refunds.view');
        $statuses = fn (string $tab) => match ($tab) {
            'requested' => ['requested'],
            'to_process' => ['approved', 'processing'],
            'processed' => ['processed'],
            default => ['rejected', 'failed'],
        };
        $base = fn () => $this->scopeByOrder(Refund::query(), $scope);
        $s = trim($this->search);
        $rows = $base()->whereIn('status', $statuses($this->tab))
            ->with(['order:id,number,customer_name', 'payment:id,method,provider,captured_amount,refunded_amount', 'requester:id,name'])
            ->when($s, fn ($q) => $q->where(fn ($w) => $w->where('number', 'ilike', "%{$s}%")->orWhereHas('order', fn ($o) => $o->where('number', 'ilike', "%{$s}%"))))
            ->latest()->paginate($this->perPage);
        $approvers = \App\Domain\Identity\Models\User::whereIn('id', $rows->pluck('approved_by')->merge($rows->pluck('processed_by'))->filter()->unique())->pluck('name', 'id');

        return view('livewire.admin.finance.refunds', [
            'rows' => $rows,
            'approvers' => $approvers,
            'counts' => collect(self::TABS)->mapWithKeys(fn ($t) => [$t => in_array($t, ['requested', 'to_process'], true) ? $base()->whereIn('status', $statuses($t))->count() : null])->all(),
            'me' => auth()->user(),
        ])->title(__('nav.refunds'));
    }
}
