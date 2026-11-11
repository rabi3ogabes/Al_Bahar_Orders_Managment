<?php

namespace App\Livewire\Admin\Sales;

use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Livewire\Admin\Concerns\WithAdminTable;
use App\Support\BusinessTime;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Order queue: quick tabs, filters (status, branch, payment, fulfillment, dates) and number/phone search, branch-scoped. */
class OrderIndex extends Component
{
    use WithAdminTable;

    public const TABS = ['all', 'needs_action', 'pending_payment', 'ready', 'out_for_delivery'];

    #[Url(except: 'all')]
    public string $tab = 'all';

    #[Url(except: '')]
    public string $filterStatus = '';

    #[Url(except: '')]
    public string $filterPayment = '';

    #[Url(except: '')]
    public string $filterMethod = '';

    #[Url(except: '')]
    public string $filterType = '';

    #[Url(except: '')]
    public string $filterFrom = '';

    #[Url(except: '')]
    public string $filterTo = '';

    public function mount(): void
    {
        abort_unless($this->can('orders.view'), 403);
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'all';
        }
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('filterStatus', 'filterPayment', 'filterMethod', 'filterType', 'filterFrom', 'filterTo', 'search', 'branch');
        $this->resetPage();
    }

    /** Orders with at least one fulfillment in the viewer's (optionally narrowed) branch scope. */
    private function scoped(): Builder
    {
        $scope = $this->branchScope('orders.view');

        return Order::query()->whereIn('id', $scope->apply(OrderFulfillment::query()->select('order_id')->getQuery(), 'branch_id'));
    }

    private function applyTab(Builder $q, string $tab): Builder
    {
        $scope = $this->branchScope('orders.view');
        $fulfillments = fn (array $statuses) => fn ($w) => $w->whereIn('id', $scope->apply(OrderFulfillment::query()->select('order_id')->whereIn('status', $statuses)->getQuery(), 'branch_id'));

        return match ($tab) {
            'needs_action' => $q->where(fn ($w) => $w->where('status', 'cancellation_requested')
                ->orWhere(fn ($x) => $x->where('status', '!=', 'pending_payment')->where($fulfillments(['pending', 'accepted', 'preparing', 'failed'])))),
            'pending_payment' => $q->where('status', 'pending_payment'),
            'ready' => $q->where($fulfillments(['ready', 'pickup_ready'])),
            'out_for_delivery' => $q->where($fulfillments(['dispatched', 'out_for_delivery'])),
            default => $q,
        };
    }

    public function render()
    {
        $q = $this->applyTab($this->scoped(), $this->tab)
            ->with(['fulfillments:id,order_id,branch_id,status,type', 'fulfillments.branch:id,code,name_en,name_ar'])
            ->when($this->filterStatus, fn ($q, $s) => $q->where('status', $s))
            ->when($this->filterPayment, fn ($q, $s) => $q->where('payment_status', $s))
            ->when($this->filterMethod, fn ($q, $s) => $q->where('payment_method', $s))
            ->when($this->filterType, fn ($q, $s) => $q->where('fulfillment_type', $s))
            ->when($this->filterFrom && strtotime($this->filterFrom), fn ($q) => $q->where('placed_at', '>=', BusinessTime::dateRange($this->filterFrom, $this->filterFrom)[0]))
            ->when($this->filterTo && strtotime($this->filterTo), fn ($q) => $q->where('placed_at', '<=', BusinessTime::dateRange($this->filterTo, $this->filterTo)[1]))
            ->when(trim($this->search), function ($q) {
                $s = trim($this->search);
                $digits = preg_replace('/\D+/', '', $s);
                $q->where(fn ($w) => $w->where('number', 'ilike', "%{$s}%")->orWhere('customer_name', 'ilike', "%{$s}%")
                    ->when(strlen($digits) >= 4, fn ($x) => $x->orWhere('customer_phone', 'like', "%{$digits}%")));
            })
            ->orderByDesc('placed_at');

        $counts = [];
        foreach (self::TABS as $t) {
            $counts[$t] = $t === 'all' ? null : $this->applyTab($this->scoped(), $t)->count();
        }
        $filtered = $this->filterStatus || $this->filterPayment || $this->filterMethod || $this->filterType || $this->filterFrom || $this->filterTo || $this->branch;

        return view('livewire.admin.sales.orders', [
            'orders' => $q->paginate($this->perPage),
            'counts' => $counts,
            'branches' => $this->scopedBranches('orders.view'),
            'filtered' => $filtered,
        ])->title(__('nav.orders'));
    }
}
