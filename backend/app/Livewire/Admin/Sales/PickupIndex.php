<?php

namespace App\Livewire\Admin\Sales;

use App\Domain\Fulfillment\Models\PickupReservation;
use App\Domain\Fulfillment\Services\PickupService;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Livewire\Admin\Concerns\WithAdminTable;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Per-branch pickup counter: passes ready for collection, orders still being prepared, and today's handovers. */
class PickupIndex extends Component
{
    use WithAdminTable;

    #[Url(except: 'ready')]
    public string $tab = 'ready';

    public function mount(): void
    {
        abort_unless($this->can('pickup.view'), 403);
        if (! in_array($this->tab, ['ready', 'preparing', 'collected'], true)) {
            $this->tab = 'ready';
        }
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function markReady(string $fulfillmentId, PickupService $pickup): void
    {
        $f = OrderFulfillment::where('type', 'pickup')->findOrFail($fulfillmentId);
        $this->authorizeBranch('pickup.edit', $f->branch_id);
        $this->attempt(fn () => $pickup->markReady($f, auth()->user()), __('sales_admin.pickup_ready_done'));
    }

    public function render()
    {
        $scope = $this->branchScope('pickup.view');
        $search = trim($this->search);
        $withOrder = fn ($q) => $q->when($search, fn ($q) => $q->whereHas('order', fn ($o) => $o->where('number', 'ilike', "%{$search}%")->orWhere('customer_name', 'ilike', "%{$search}%")
            ->orWhere('customer_phone', 'like', '%'.preg_replace('/\D+/', '', $search).'%')));

        $base = fn () => tap(PickupReservation::query(), fn ($q) => $scope->apply($q->getQuery(), 'branch_id'));

        $counts = [
            'ready' => $base()->whereIn('status', ['ready', 'partially_collected'])->count(),
            'preparing' => tap(OrderFulfillment::query()->where('type', 'pickup')->whereIn('status', ['pending', 'accepted', 'preparing'])
                ->whereHas('order', fn ($o) => $o->where('status', '!=', 'pending_payment')), fn ($q) => $scope->apply($q->getQuery(), 'branch_id'))->count(),
            'collected' => $base()->where('status', 'collected')->where('collected_at', '>=', now()->startOfDay())->count(),
        ];

        if ($this->tab === 'preparing') {
            $rows = tap(OrderFulfillment::query()->where('type', 'pickup')->whereIn('status', ['pending', 'accepted', 'preparing'])
                ->whereHas('order', fn ($o) => $o->where('status', '!=', 'pending_payment')), fn ($q) => $scope->apply($q->getQuery(), 'branch_id'))
                ->with(['order:id,number,customer_name,customer_phone,payment_method,payment_status,grand_total,paid_total,placed_at', 'branch:id,code,name_en,name_ar'])->withSum('items as units', 'quantity')
                ->tap($withOrder)->orderBy('created_at')->paginate($this->perPage);
        } else {
            $rows = $base()->when($this->tab === 'collected', fn ($q) => $q->where('status', 'collected')->where('collected_at', '>=', now()->startOfDay()), fn ($q) => $q->whereIn('status', ['ready', 'partially_collected']))
                ->with(['fulfillment.order:id,number,customer_name,customer_phone,payment_method,payment_status,grand_total,paid_total', 'branch:id,code,name_en,name_ar', 'fulfillment.items:id,order_fulfillment_id,quantity,quantity_cancelled,quantity_handed_over'])
                ->when($search, fn ($q) => $q->whereHas('fulfillment.order', fn ($o) => $o->where('number', 'ilike', "%{$search}%")->orWhere('customer_name', 'ilike', "%{$search}%")))
                ->orderBy($this->tab === 'collected' ? 'collected_at' : 'ready_at', $this->tab === 'collected' ? 'desc' : 'asc')->paginate($this->perPage);
        }

        return view('livewire.admin.sales.pickup', [
            'rows' => $rows,
            'counts' => $counts,
            'branches' => $this->scopedBranches('pickup.view'),
            'canScan' => $this->can('pickup.approve'),
        ])->title(__('nav.pickup'));
    }
}
