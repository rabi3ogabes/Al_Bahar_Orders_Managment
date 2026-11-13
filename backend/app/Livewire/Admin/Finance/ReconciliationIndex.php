<?php

namespace App\Livewire\Admin\Finance;

use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Services\PaymentService;
use App\Domain\Reports\Services\ReportService;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Livewire\Admin\Sales\Concerns\HasRecordWindow;
use App\Livewire\Admin\Sales\Concerns\PeeksAtOrder;
use App\Support\BusinessTime;
use App\Support\DomainException;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/** SADAD reconciliation: provider vs ledger check per payment (ReportService) with re-check and resolution actions. */
class ReconciliationIndex extends Component
{
    use HasRecordWindow, InteractsWithAdmin, PeeksAtOrder;

    /** The payment shown in the side window (`?view=`). */
    #[Locked]
    #[Url(as: 'view', except: '')]
    public string $viewId = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: true)]
    public bool $exceptionsOnly = true;

    public bool $showResolve = false;

    #[Locked]
    public ?string $target = null;

    public string $note = '';

    public function mount(): void
    {
        abort_unless($this->can('reconciliation.view'), 403);
        $this->from = $this->from ?: BusinessTime::now()->subDays(6)->toDateString();
        $this->to = $this->to ?: BusinessTime::now()->toDateString();
        if ($this->viewId !== '') {
            $this->openView($this->viewId, quiet: true);
        }
    }

    protected function findViewable(string $id): ?Model
    {
        $payment = Payment::find($id);

        return $payment && PaymentShow::viewableBy(auth()->user(), $payment) ? $payment : null;
    }

    public function recheck(string $paymentId, PaymentService $svc): void
    {
        $outcome = $this->attempt(fn () => $svc->recheck(Payment::where('provider', 'sadad')->findOrFail($paymentId), auth()->user()));
        if (is_string($outcome)) {
            $this->toast(__('finance_admin.recheck_result', ['outcome' => __('finance_admin.outcome_'.$outcome)]), $outcome === 'unavailable' ? 'warning' : 'success');
        }
    }

    public function askResolve(string $paymentId): void
    {
        abort_unless($this->can('payments.override'), 403);
        $this->target = Payment::where('provider', 'sadad')->findOrFail($paymentId)->id;
        $this->reset('note');
        $this->resetValidation();
        $this->showResolve = true;
    }

    public function resolve(PaymentService $svc): void
    {
        $this->validate(['note' => 'required|string|min:5|max:1000'], [], ['note' => __('finance_admin.resolution')]);
        if ($this->attempt(fn () => $svc->resolveReview(Payment::findOrFail((string) $this->target), auth()->user(), trim($this->note)), __('finance_admin.review_resolved'))) {
            $this->showResolve = false;
        }
    }

    public function render(ReportService $reports)
    {
        $from = strtotime($this->from) ? $this->from : BusinessTime::now()->toDateString();
        $to = strtotime($this->to) && $this->to >= $from ? $this->to : $from;
        $error = null;
        $result = ['rows' => [], 'summary' => ['exceptions' => 0, 'captured' => '0.00']];
        try {
            $result = $reports->run(auth()->user(), 'sadad_reconciliation', $from, $to);
        } catch (DomainException $e) {
            $error = $e->getMessage();
        }
        $rows = collect($result['rows']);
        $payments = Payment::whereIn('id', $rows->pluck('id'))->with('order:id,number')->get()->keyBy('id');
        $checks = $rows->countBy('check');

        return view('livewire.admin.finance.reconciliation', [
            'rows' => $this->exceptionsOnly ? $rows->where('check', '!=', 'ok')->values() : $rows,
            'total' => $rows->count(),
            'checks' => $checks,
            'summary' => $result['summary'],
            'payments' => $payments,
            'error' => $error,
            'canResolve' => $this->can('payments.override'),
            'canRecheck' => $this->can('payments.edit'),
        ])->title(__('nav.reconciliation'));
    }
}
