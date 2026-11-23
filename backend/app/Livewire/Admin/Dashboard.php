<?php

namespace App\Livewire\Admin;

use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Services\OtpTestMode;
use App\Domain\Reports\Services\DashboardMetrics;
use App\Support\AdminNavigation;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The owner's dashboard. What a person sees depends on what their role may see: money and order tiles for orders/reports, the stock tile for
 * inventory, the work lists they can open, and the System health card for the people who manage settings. A role with none of those still gets a
 * welcome and shortcuts to the pages it can open, never a page of zeros.
 */
class Dashboard extends Component
{
    /** The permissions whose branches the branch picker offers: the sections of this page. */
    private const BRANCH_PERMISSIONS = ['orders.view', 'reports.view', 'payments.view', 'inventory.view', 'pickup.view', 'deliveries.view'];

    #[Url(as: 'branch')]
    public ?string $branchId = null;

    public function render(DashboardMetrics $metrics)
    {
        $user = auth()->user();
        $acl = app(AccessControl::class);
        $branchId = $this->branchId !== null && Str::isUuid($this->branchId) ? $this->branchId : null; // a made-up branch in the address means "all my branches"

        $scopes = array_map(fn (string $p) => $acl->scope($user, $p), self::BRANCH_PERMISSIONS);
        $allBranches = collect($scopes)->contains(fn ($s) => $s->all);
        $ids = collect($scopes)->flatMap(fn ($s) => $s->branchIds)->unique()->values()->all();
        $branches = Branch::query()->when(! $allBranches, fn ($q) => $q->whereIn('id', $ids))->orderBy('sort_order')->get();

        $m = $metrics->for($user, $branchId);
        $hasNumbers = collect($m['can'])->contains(true) || $m['live_auctions'] !== null || $m['open_tickets'] !== null || $m['pending_refunds'] !== null;

        return view('livewire.admin.dashboard', [
            'm' => $m,
            'branches' => $branches,
            'testCodesOn' => $user->isSuperAdmin() && app(OtpTestMode::class)->active(),
            'showHealth' => $acl->allowsGlobally($user, 'settings.edit'),
            'shortcuts' => $hasNumbers ? [] : collect(AdminNavigation::for($user))->flatMap(fn (array $g) => $g['items'])->reject(fn (array $i) => $i['route'] === 'admin.dashboard')->take(12)->values()->all(),
            'greeting' => match (true) {
                now(config('platform.business_timezone'))->hour < 12 => __('admin.good_morning'),
                now(config('platform.business_timezone'))->hour < 18 => __('admin.good_afternoon'),
                default => __('admin.good_evening'),
            },
        ])->title(__('nav.dashboard'));
    }
}
