<?php

namespace App\Livewire\Admin\Sales;

use App\Domain\Branches\Models\Branch;
use App\Domain\Fulfillment\Services\DispatchService;
use App\Livewire\Admin\Concerns\WithAdminTable;
use Livewire\Component;

/** Live fleet: every driver on duty on a map with their current job. Moves arrive over Reverb; a 15 s poll is the fallback. */
class FleetMap extends Component
{
    use WithAdminTable;

    public function render(DispatchService $dispatch)
    {
        $scope = $this->branchScope('deliveries.view');
        $fleet = $dispatch->fleet($scope->all ? null : $scope->branchIds);
        $orders = $this->branchScope('orders.view');

        $this->dispatch('fleet-updated', drivers: $fleet->values()->all());

        return view('livewire.admin.sales.fleet-map', [
            'fleet' => $fleet,
            // Company-wide staff listen on private-fleet; branch staff on the private channel of each of their branches.
            'channels' => $scope->all ? ['fleet'] : array_map(fn ($b) => 'branches.'.$b, $scope->branchIds),
            // New-order / status toasts: staff who see every branch's orders hear private-live-orders, the others one channel per branch.
            'liveChannels' => $orders->all ? ['live-orders'] : array_map(fn ($b) => 'live-orders.'.$b, $orders->branchIds),
            'liveText' => $this->liveText(),
            'branches' => Branch::whereIn('id', $this->scopedBranches('deliveries.view')->pluck('id'))->orderBy('sort_order')->get(['id', 'code', 'name_en', 'name_ar', 'latitude', 'longitude']),
        ])->title(__('nav.fleet'));
    }

    /** @return array<string, string> the toast strings, in the staff member's language */
    private function liveText(): array
    {
        $status = collect(trans('status'))->filter(fn ($v) => is_string($v))->mapWithKeys(fn ($v, $k) => ['status.'.$k => $v]);

        return $status->merge(trans('live'))->all();
    }
}
