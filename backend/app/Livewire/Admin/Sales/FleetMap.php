<?php

namespace App\Livewire\Admin\Sales;

use App\Domain\Branches\Models\Branch;
use App\Domain\Fulfillment\Services\DispatchService;
use App\Livewire\Admin\Concerns\WithAdminTable;
use App\Livewire\Admin\Sales\Concerns\PeeksAtOrder;
use App\Support\MapConfig;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Live fleet: every driver on duty on a map with their current job. Moves arrive over Reverb; the page refreshes this
 * component every 30 s while the socket is up and every 15 s while it is not (the map script drives that, see
 * resources/js/maps/fleet.js). Each render hands the map its rows, branch pins and channels through "fleet-updated".
 * A driver's "open order" link opens the order in a side window over the map (the link keeps the order page's URL for middle-click).
 */
class FleetMap extends Component
{
    use PeeksAtOrder, WithAdminTable;

    public function render(DispatchService $dispatch)
    {
        $scope = $this->branchScope('deliveries.view');
        $orders = $this->branchScope('orders.view');
        $fleet = $dispatch->fleet($scope->all ? null : $scope->branchIds);
        $branches = Branch::whereIn('id', $this->scopedBranches('deliveries.view')->pluck('id'))->orderBy('sort_order')->get(['id', 'code', 'name_en', 'name_ar', 'latitude', 'longitude']);

        $live = [
            'drivers' => $fleet->values()->all(),
            'branches' => $this->branchPins($branches),
            // Company-wide staff listen on private-fleet; branch staff on the private channel of each of their branches.
            'channels' => $scope->all ? ['fleet'] : array_map(fn ($b) => 'branches.'.$b, $scope->branchIds),
            'branch' => $this->branch,
            'server_time' => now()->getTimestampMs(),
        ];
        $this->dispatch('fleet-updated', ...$live);

        return view('livewire.admin.sales.fleet-map', [
            'boot' => $live + [
                'config' => MapConfig::payload(),
                'orderUrl' => route('admin.orders.show', '__ID__'),
                'canCall' => true,
            ],
            // New-order / status toasts: staff who see every branch's orders hear private-live-orders, the others one channel per branch.
            'liveChannels' => $orders->all ? ['live-orders'] : array_map(fn ($b) => 'live-orders.'.$b, $orders->branchIds),
            'liveText' => $this->liveText(),
            'branches' => $branches,
        ])->title(__('nav.fleet'));
    }

    /** @return list<array{id: string, name: string, lat: float, lng: float}> branches that have a position, named in the staff member's language */
    private function branchPins(Collection $branches): array
    {
        $ar = app()->getLocale() === 'ar';

        return $branches->filter(fn ($b) => $b->latitude !== null && $b->longitude !== null)
            ->map(fn ($b) => ['id' => $b->id, 'name' => $ar ? ($b->name_ar ?: $b->name_en) : $b->name_en, 'lat' => (float) $b->latitude, 'lng' => (float) $b->longitude])
            ->values()->all();
    }

    /** @return array<string, string> the toast strings, in the staff member's language */
    private function liveText(): array
    {
        $status = collect(trans('status'))->filter(fn ($v) => is_string($v))->mapWithKeys(fn ($v, $k) => ['status.'.$k => $v]);

        return $status->merge(trans('live'))->all();
    }
}
