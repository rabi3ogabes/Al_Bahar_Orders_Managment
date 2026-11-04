<?php

namespace App\Livewire\Admin\Finance;

use App\Domain\Payments\Models\CashCollection;
use App\Livewire\Admin\Concerns\WithAdminTable;
use App\Support\BusinessTime;
use Livewire\Attributes\Url;
use Livewire\Component;

/** COD and pay-at-pickup collections by branch, collector and tender, with outstanding hand-in totals. */
class CashIndex extends Component
{
    use WithAdminTable;

    #[Url(except: '')]
    public string $filterContext = '';

    #[Url(except: '')]
    public string $filterTender = '';

    #[Url(except: '')]
    public string $filterStatus = '';

    #[Url(except: '')]
    public string $filterFrom = '';

    #[Url(except: '')]
    public string $filterTo = '';

    public function mount(): void
    {
        abort_unless($this->can('payments.view'), 403);
        $this->filterFrom = $this->filterFrom ?: BusinessTime::now()->subDays(6)->toDateString();
        $this->filterTo = $this->filterTo ?: BusinessTime::now()->toDateString();
    }

    public function render()
    {
        $scope = $this->branchScope('payments.view');
        $from = strtotime($this->filterFrom) ? $this->filterFrom : BusinessTime::now()->toDateString();
        $to = strtotime($this->filterTo) && $this->filterTo >= $from ? $this->filterTo : $from;
        [$start, $end] = BusinessTime::dateRange($from, $to);
        $s = trim($this->search);
        $base = fn () => tap(CashCollection::query()->whereBetween('collected_at', [$start, $end]), fn ($q) => $scope->apply($q->getQuery(), 'branch_id'))
            ->when($this->filterContext, fn ($q, $c) => $q->where('context', $c))
            ->when($this->filterTender, fn ($q, $t) => $q->where('tender', $t))
            ->when($this->filterStatus, fn ($q, $st) => $q->where('status', $st))
            ->when($s, fn ($q) => $q->where(fn ($w) => $w->where('receipt_reference', 'ilike', "%{$s}%")->orWhereHas('payment.order', fn ($o) => $o->where('number', 'ilike', "%{$s}%"))
                ->orWhereHas('collector', fn ($u) => $u->where('name', 'ilike', "%{$s}%"))));

        $totals = $base()->selectRaw("coalesce(sum(amount_collected),0) as collected,
            coalesce(sum(CASE WHEN tender = 'cash' THEN amount_collected ELSE 0 END),0) as cash,
            coalesce(sum(CASE WHEN tender = 'card_terminal' THEN amount_collected ELSE 0 END),0) as card,
            coalesce(sum(CASE WHEN handed_in_at IS NULL AND tender = 'cash' THEN amount_collected ELSE 0 END),0) as not_handed_in,
            sum(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed")->first();

        return view('livewire.admin.finance.cash', [
            'rows' => $base()->with(['payment.order:id,number,customer_name', 'collector:id,name', 'branch:id,code'])->latest('collected_at')->paginate($this->perPage),
            'totals' => $totals,
            'branches' => $this->scopedBranches('payments.view'),
        ])->title(__('nav.cash'));
    }
}
