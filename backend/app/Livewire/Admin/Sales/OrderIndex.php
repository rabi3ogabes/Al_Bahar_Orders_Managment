<?php

namespace App\Livewire\Admin\Sales;

use App\Domain\Operations\Services\OpsClock;
use App\Domain\Operations\Support\OpsAge;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Livewire\Admin\Concerns\WithAdminTable;
use App\Livewire\Admin\Sales\Concerns\PeeksAtOrder;
use App\Support\BusinessTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Order queue: quick tabs, filters (status, branch, payment, fulfillment, dates) and number/phone search, branch-scoped.
 * A row opens the order in a side window (`?view=<order id>`), so the list keeps its filters, search, page and scroll.
 */
class OrderIndex extends Component
{
    use PeeksAtOrder {
        peekOrder as private openPeek;
    }
    use WithAdminTable;

    public const TABS = ['all', 'needs_action', 'pending_payment', 'ready', 'out_for_delivery'];

    /** Sort orders by "oldest first" (the longest waiting at the top): `?sort=oldest`. Anything else is newest first. */
    public const SORT_OLDEST = 'oldest';

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

    /** The order shown in the side window; mirrors the window state so reload, sharing and Back work. */
    #[Locked]
    #[Url(as: 'view', except: '')]
    public string $viewId = '';

    public function mount(): void
    {
        abort_unless($this->can('orders.view'), 403);
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'all';
        }
        if ($this->viewId !== '') {
            $this->peekOrder($this->viewId, quiet: true);
        }
    }

    /** Opens the window and mirrors it in the address bar (`?view=`). */
    public function peekOrder(string $id, bool $quiet = false): void
    {
        $this->openPeek($id, $quiet);
        $this->viewId = $this->showOrder ? $this->orderPeekId : '';
    }

    public function closeOrderPeek(): void
    {
        $this->showOrder = false;
        $this->viewId = '';
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('quick', 'filterStatus', 'filterPayment', 'filterMethod', 'filterType', 'filterFrom', 'filterTo', 'search', 'branch');
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

    /** One-tap filter chips: cod | sadad | pickup ('' = all). They sit on top of the tab, search and the other filters. */
    #[Url(except: '')]
    public string $quick = '';

    private function applyQuick(Builder $q, string $quick): Builder
    {
        return match ($quick) {
            'cod' => $q->where('payment_method', 'cod'),
            'sadad' => $q->where('payment_method', 'sadad'),
            'pickup' => $q->where('fulfillment_type', 'pickup'),
            default => $q,
        };
    }

    public function setQuick(string $quick): void
    {
        $this->quick = in_array($quick, ['cod', 'sadad', 'pickup'], true) && $this->quick !== $quick ? $quick : '';
        $this->resetPage();
    }

    /**
     * What each order on the page is waiting for, as its worst open wait (new, being prepared, ready with no driver, driver silent, pickup, failed):
     * order id => OpsAge. Measured from rows already loaded (the part's order and deliveries are set from the page's own query): no extra query per row.
     *
     * @param  iterable<Model>  $orders  the page's orders (anything else is skipped)
     * @return array<string, OpsAge>
     */
    private function waits(iterable $orders, OpsClock $clock): array
    {
        $branchIds = [];
        foreach ($orders as $o) {
            if (! $o instanceof Order) {
                continue;
            }
            foreach ($o->fulfillments as $f) {
                $branchIds[] = $f->branch_id;
            }
        }
        $clock->preload($branchIds);
        $waits = [];
        foreach ($orders as $o) {
            if (! $o instanceof Order) {
                continue;
            }
            foreach ($o->fulfillments as $f) {
                $f->setRelation('order', $o);
                $age = $clock->forFulfillment($f);
                $worst = $waits[$o->id] ?? null;
                if ($age !== null && ($worst === null || $age->rank() > $worst->rank() || ($age->rank() === $worst->rank() && $age->ageMinutes > $worst->ageMinutes))) {
                    $waits[$o->id] = $age;
                }
            }
        }

        return $waits;
    }

    public function render(OpsClock $clock)
    {
        $quickCounts = [];
        foreach (['cod', 'sadad', 'pickup'] as $k) {
            $quickCounts[$k] = $this->applyQuick($this->applyTab($this->scoped(), $this->tab), $k)->count();
        }
        $q = $this->applyQuick($this->applyTab($this->scoped(), $this->tab), $this->quick)
            ->with(['fulfillments:id,order_id,branch_id,status,type,accepted_at,ready_at,created_at,updated_at', 'fulfillments.branch:id,code,name_en,name_ar',
                'fulfillments.deliveries:id,order_fulfillment_id,status,created_at,assigned_at,failed_at'])
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
            ->orderBy('placed_at', $this->sort === self::SORT_OLDEST ? 'asc' : 'desc');

        $counts = [];
        foreach (self::TABS as $t) {
            $counts[$t] = $t === 'all' ? null : $this->applyTab($this->scoped(), $t)->count();
        }
        $orders = $q->paginate($this->perPage);
        $filtered = $this->quick || $this->filterStatus || $this->filterPayment || $this->filterMethod || $this->filterType || $this->filterFrom || $this->filterTo || $this->branch;

        return view('livewire.admin.sales.orders', [
            'orders' => $orders,
            'waits' => $this->waits($orders->getCollection(), $clock),
            'counts' => $counts,
            'quickCounts' => $quickCounts,
            'branches' => $this->scopedBranches('orders.view'),
            'filtered' => $filtered,
        ])->title(__('nav.orders'));
    }
}
