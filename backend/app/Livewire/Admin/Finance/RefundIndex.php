<?php

namespace App\Livewire\Admin\Finance;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\FreshTwoFactor;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\Services\RefundApprovalPolicy;
use App\Domain\Payments\Services\RefundService;
use App\Livewire\Admin\Concerns\WithAdminTable;
use App\Livewire\Admin\Finance\Concerns\ScopesPayments;
use App\Livewire\Admin\Sales\Concerns\PeeksAtOrder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Refund queue: requested → approved (by a different person: dual control, enforced by RefundService) →
 * processed via the gateway API or manually with a bank/cash reference when the API is unavailable.
 */
class RefundIndex extends Component
{
    use PeeksAtOrder, ScopesPayments, WithAdminTable;

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

    /** Approving your own request (a one-person shop): the written reason and the two-factor code. */
    public string $selfReason = '';

    public string $code = '';

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
        abort_unless(in_array($dialog, ['reject', 'process', 'self'], true), 422);
        $this->refund($id);
        $this->dialog = $dialog;
        $this->target = $id;
        $this->reset('note', 'reference', 'selfReason', 'code');
        $this->resetValidation();
        $this->showDialog = true;
    }

    public function confirmDialog(RefundService $svc): void
    {
        $r = $this->refund((string) $this->target);
        $perm = in_array($this->dialog, ['reject', 'self'], true) ? 'refunds.approve' : 'refunds.refund';
        foreach ($r->payment->order?->branchIds() ?? [] as $b) {
            $this->authorizeBranch($perm, $b);
        }
        if ($this->dialog === 'self') {
            $ok = $this->approveOwn($r, $svc);
        } elseif ($this->dialog === 'reject') {
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

    /** The requester approves their own request: only when nobody else can (RefundService re-checks), with a reason and a fresh code. */
    private function approveOwn(Refund $r, RefundService $svc): bool
    {
        $user = auth()->user();
        $fresh = app(FreshTwoFactor::class);
        $this->resetErrorBag('code');
        $this->validate(['selfReason' => 'required|string|min:10|max:500'], [], ['selfReason' => __('admin.reason')]);
        if (! $fresh->isFresh()) {
            $this->validate(['code' => 'required|string|max:32'], [], ['code' => __('finance_admin.two_factor_code')]);
            if (! $fresh->confirm($user, $this->code)) {
                $this->addError('code', __('errors.two_factor_invalid'));

                return false;
            }
        }

        return (bool) $this->attempt(fn () => $svc->approve($r, $user, $this->selfReason, true), __('finance_admin.refund_self_approved'));
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
            ->with(['order:id,number,customer_name', 'order.fulfillments:id,order_id,branch_id', 'payment:id,method,provider,captured_amount,refunded_amount', 'requester:id,name'])
            ->when($s, fn ($q) => $q->where(fn ($w) => $w->where('number', 'ilike', "%{$s}%")->orWhereHas('order', fn ($o) => $o->where('number', 'ilike', "%{$s}%"))))
            ->latest()->paginate($this->perPage);
        $approvers = User::whereIn('id', $rows->pluck('approved_by')->merge($rows->pluck('processed_by'))->filter()->unique())->pluck('name', 'id');

        return view('livewire.admin.finance.refunds', [
            'rows' => $rows,
            'approvers' => $approvers,
            'counts' => collect(self::TABS)->mapWithKeys(fn ($t) => [$t => in_array($t, ['requested', 'to_process'], true) ? $base()->whereIn('status', $statuses($t))->count() : null])->all(),
            'me' => auth()->user(),
            'needsCode' => ! app(FreshTwoFactor::class)->isFresh(),
            'own' => self::pageRows($rows->items())->filter(fn (Refund $r) => $r->status === 'requested')->mapWithKeys(fn (Refund $r) => [$r->id => app(RefundApprovalPolicy::class)->stateFor($r, auth()->user())])->filter()->all(),
        ])->title(__('nav.refunds'));
    }

    /**
     * The refunds on the current page, typed for the approval column.
     *
     * @param  array<int, mixed>  $items
     * @return Collection<int, Refund>
     */
    private static function pageRows(array $items): Collection
    {
        $refunds = [];
        foreach ($items as $r) {
            if ($r instanceof Refund) {
                $refunds[] = $r;
            }
        }

        return collect($refunds);
    }
}
