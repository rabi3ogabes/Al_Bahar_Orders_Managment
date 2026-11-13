<?php

namespace App\Livewire\Admin\Finance;

use App\Domain\Payments\Models\Payment;
use App\Livewire\Admin\Concerns\WithAdminTable;
use App\Livewire\Admin\Finance\Concerns\ScopesPayments;
use App\Livewire\Admin\Sales\Concerns\HasRecordWindow;
use App\Livewire\Admin\Sales\Concerns\PeeksAtOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * All payment attempts (SADAD, COD, pay-at-pickup) with status/method filters and finance review flags.
 * A row opens the payment in a side window (`?view=<payment id>`); its order opens in a second window on top.
 */
class PaymentIndex extends Component
{
    use HasRecordWindow, PeeksAtOrder, ScopesPayments, WithAdminTable;

    #[Locked]
    #[Url(as: 'view', except: '')]
    public string $viewId = '';

    #[Url(except: '')]
    public string $filterStatus = '';

    #[Url(except: '')]
    public string $filterMethod = '';

    /** late_capture | amount_mismatch | failed_pending */
    #[Url(except: '')]
    public string $filterFlag = '';

    public function mount(): void
    {
        abort_unless($this->can('payments.view'), 403);
        if ($this->viewId !== '') {
            $this->openView($this->viewId, quiet: true);
        }
    }

    protected function findViewable(string $id): ?Model
    {
        $payment = Payment::find($id);

        return $payment && PaymentShow::viewableBy(auth()->user(), $payment) ? $payment : null;
    }

    public static function applyFlag(Builder $q, string $flag): Builder
    {
        return match ($flag) {
            'late_capture' => $q->whereRaw("metadata->'late_capture' IS NOT NULL"),
            'amount_mismatch' => $q->where('status', 'processing')->whereExists(fn ($t) => $t->from('payment_transactions as t')->whereColumn('t.payment_id', 'payments.id')
                ->where('t.status', 'paid')->whereNotNull('t.amount')->whereColumn('t.amount', '<>', 'payments.amount')),
            'needs_attention' => $q->where(fn ($w) => $w->whereIn('status', ['pending', 'processing'])->where('created_at', '<', now()->subMinutes(30))
                ->orWhereRaw("metadata->'late_capture' IS NOT NULL")),
            default => $q,
        };
    }

    public function render()
    {
        $scope = $this->branchScope('payments.view');
        $s = trim($this->search);
        $q = $this->scopeByOrder(Payment::query(), $scope)->with('order:id,number,customer_name')
            ->when($this->filterStatus, fn ($q, $st) => $q->where('status', $st))
            ->when($this->filterMethod, fn ($q, $m) => $q->where('method', $m))
            ->when($this->filterFlag, fn ($q, $f) => self::applyFlag($q, $f))
            ->when($s, fn ($q) => $q->where(fn ($w) => $w->where('provider_reference', 'ilike', "%{$s}%")->orWhereHas('order', fn ($o) => $o->where('number', 'ilike', "%{$s}%"))))
            ->latest();

        $flagCounts = [
            'late_capture' => self::applyFlag($this->scopeByOrder(Payment::query(), $scope), 'late_capture')->count(),
            'amount_mismatch' => self::applyFlag($this->scopeByOrder(Payment::query(), $scope), 'amount_mismatch')->count(),
        ];
        $mismatchIds = self::applyFlag($this->scopeByOrder(Payment::query(), $scope), 'amount_mismatch')->pluck('id')->all();

        return view('livewire.admin.finance.payments', [
            'payments' => $q->paginate($this->perPage),
            'flagCounts' => $flagCounts,
            'mismatchIds' => $mismatchIds,
            'branches' => $this->scopedBranches('payments.view'),
        ])->title(__('nav.payments'));
    }
}
