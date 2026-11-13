<?php

namespace App\Livewire\Admin\Finance;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Services\PaymentService;
use App\Domain\Payments\Services\RefundService;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Support\Money;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Payment detail: provider transactions ledger, refunds and collections, plus re-check / resolve / request-refund. */
class PaymentShow extends Component
{
    use InteractsWithAdmin {
        attempt as private runAttempt;
    }

    #[Locked]
    public string $paymentId;

    /** Compact mode inside a side window (list → record view): same data and actions, no page chrome. */
    #[Locked]
    public bool $drawer = false;

    public bool $showRefund = false;

    public array $refund = ['amount' => '', 'reason_code' => 'customer_request', 'reason' => '', 'method' => 'original'];

    public string $resolution = '';

    #[Locked]
    public string $refundKey = '';

    public function mount(Payment $payment, bool $drawer = false): void
    {
        abort_unless(self::viewableBy(auth()->user(), $payment), 403);
        $this->paymentId = $payment->id;
        $this->drawer = $drawer;
    }

    /** The page's own gate, shared with the side window: payments.view at a branch of the order (or company-wide for order-less payments). */
    public static function viewableBy(?User $user, Payment $p): bool
    {
        if (! $user) {
            return false;
        }
        $acl = app(AccessControl::class);
        $branches = $p->order?->branchIds() ?? [];

        return $branches ? collect($branches)->contains(fn ($b) => $acl->allows($user, 'payments.view', $b)) : $acl->allowsGlobally($user, 'payments.view');
    }

    /** A successful action inside a side window tells the list behind it to refresh its row. */
    protected function attempt(callable $action, ?string $success = null): mixed
    {
        $result = $this->runAttempt($action, $success);
        if ($result !== null && $this->drawer) {
            $this->dispatch('record-changed');
        }

        return $result;
    }

    private function payment(): Payment
    {
        return Payment::findOrFail($this->paymentId);
    }

    public function recheck(PaymentService $svc): void
    {
        $outcome = $this->attempt(fn () => $svc->recheck($this->payment(), auth()->user()));
        if (is_string($outcome)) {
            $this->toast(__('finance_admin.recheck_result', ['outcome' => __('finance_admin.outcome_'.$outcome)]), $outcome === 'unavailable' ? 'warning' : 'success');
        }
    }

    public function resolve(PaymentService $svc): void
    {
        abort_unless($this->can('payments.override'), 403);
        $this->validate(['resolution' => 'required|string|min:5|max:1000'], [], ['resolution' => __('finance_admin.resolution')]);
        if ($this->attempt(fn () => $svc->resolveReview($this->payment(), auth()->user(), trim($this->resolution)), __('finance_admin.review_resolved'))) {
            $this->reset('resolution');
        }
    }

    public function openRefund(): void
    {
        $p = $this->payment();
        $this->refund = ['amount' => $p->refundable()->toDecimal(), 'reason_code' => 'customer_request', 'reason' => '', 'method' => 'original'];
        $this->refundKey = (string) Str::uuid();
        $this->resetValidation();
        $this->showRefund = true;
    }

    public function requestRefund(RefundService $svc): void
    {
        $data = $this->validate([
            'refund.amount' => 'required|numeric|min:0.01',
            'refund.reason_code' => 'required|in:customer_request,order_cancelled,damaged,goodwill,duplicate_charge,other',
            'refund.reason' => 'nullable|string|max:1000',
            'refund.method' => 'required|in:original,manual_cash,manual_bank_transfer',
        ], [], ['refund.amount' => __('finance_admin.amount')])['refund'];
        $p = $this->payment();
        $r = $this->attempt(fn () => $svc->request($p, Money::of((string) $data['amount']), $data['reason_code'], $data['reason'] ?: null, auth()->user(), 'staff:'.$this->refundKey, $data['method']), __('finance_admin.refund_requested'));
        if ($r) {
            $this->showRefund = false;
        }
    }

    public function render()
    {
        $p = Payment::with(['order:id,number,customer_name,status,payment_status', 'transactions' => fn ($q) => $q->orderByDesc('created_at'), 'refunds.requester:id,name', 'collections.collector:id,name', 'collections.branch:id,code'])->findOrFail($this->paymentId);
        $branches = $p->order?->branchIds() ?? [];
        $canRefund = $p->status->isSettled() && $p->refundable()->isPositive() && ($branches
            ? collect($branches)->every(fn ($b) => $this->can('refunds.create', $b)) : $this->canGlobally('refunds.create'));
        $canEdit = $branches ? collect($branches)->contains(fn ($b) => $this->can('payments.edit', $b)) : $this->canGlobally('payments.edit');
        $mismatch = $p->status->value === 'processing' && $p->transactions->contains(fn ($t) => $t->status === 'paid' && $t->amount !== null && ! Money::of($t->amount)->equals(Money::of($p->amount)));

        return view('livewire.admin.finance.payment-show', [
            'p' => $p,
            'canRecheck' => $canEdit && $p->provider === 'sadad' && in_array($p->status->value, ['pending', 'processing'], true),
            'canRefund' => $canRefund,
            'flagged' => ! empty($p->metadata['late_capture']) || $mismatch,
            'mismatch' => $mismatch,
            'canResolve' => $this->can('payments.override'),
        ])->title(__('finance_admin.payment'));
    }
}
