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

        $this->dispatch('fleet-updated', drivers: $fleet->values()->all());

        return view('livewire.admin.sales.fleet-map', [
            'fleet' => $fleet,
            'branches' => Branch::whereIn('id', $this->scopedBranches('deliveries.view')->pluck('id'))->orderBy('sort_order')->get(['id', 'code', 'name_en', 'name_ar', 'latitude', 'longitude']),
        ])->title(__('nav.fleet'));
    }
}
