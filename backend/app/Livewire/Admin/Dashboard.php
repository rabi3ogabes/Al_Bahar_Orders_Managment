<?php

namespace App\Livewire\Admin;

use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Reports\Services\DashboardMetrics;
use Livewire\Attributes\Url;
use Livewire\Component;

class Dashboard extends Component
{
    #[Url(as: 'branch')]
    public ?string $branchId = null;

    public function render(DashboardMetrics $metrics)
    {
        $user = auth()->user();
        $scope = app(AccessControl::class)->scope($user, 'orders.view');
        $branches = Branch::query()->when(! $scope->all, fn ($q) => $q->whereIn('id', $scope->branchIds))->orderBy('sort_order')->get();

        return view('livewire.admin.dashboard', [
            'm' => $metrics->for($user, $this->branchId),
            'branches' => $branches,
            'greeting' => match (true) {
                now(config('platform.business_timezone'))->hour < 12 => __('admin.good_morning'),
                now(config('platform.business_timezone'))->hour < 18 => __('admin.good_afternoon'),
                default => __('admin.good_evening'),
            },
        ])->title(__('nav.dashboard'));
    }
}
