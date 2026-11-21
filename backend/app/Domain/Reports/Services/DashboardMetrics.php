<?php

namespace App\Domain\Reports\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Support\BranchScope;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Headline numbers for the dashboard. Every figure is restricted to the viewer's authorized branches, and a section is computed only for a viewer
 * who may see it (`can` says which): a marketing, stock or auction manager is shown their own work, not a wall of zeros.
 *
 * The sales figures come from {@see SalesBasis}, the one definition the reports use too:
 *  - sales_today:     orders placed today (shop time), not cancelled, not waiting for online payment, at their booked value;
 *  - collected_today: money that actually came in today: online payments captured plus cash and card collections recorded by staff;
 *  - orders_today:    how many of those orders.
 */
class DashboardMetrics
{
    public function __construct(private readonly AccessControl $access) {}

    /** @return array<string, mixed> */
    public function for(User $user, ?string $branchId = null): array
    {
        $can = [
            'sales' => $this->access->allows($user, 'orders.view') || $this->access->allows($user, 'reports.view'),
            'collected' => $this->access->allows($user, 'payments.view') || $this->access->allows($user, 'reports.view'),
            'orders' => $this->access->allows($user, 'orders.view'),
            'pickup' => $this->access->allows($user, 'pickup.view'),
            'deliveries' => $this->access->allows($user, 'deliveries.view'),
            'stock' => $this->access->allows($user, 'inventory.view'),
        ];
        $salesScope = $this->access->scope($user, $this->access->allows($user, 'orders.view') ? 'orders.view' : 'reports.view')->narrow($branchId);

        $out = [
            'can' => $can,
            'sales_today' => null, 'collected_today' => null, 'collected_online' => null, 'collected_cash' => null, 'orders_today' => null,
            'pending_fulfillments' => null, 'ready_for_pickup' => null, 'out_for_delivery' => null, 'low_stock' => null, 'out_of_stock' => null,
            'low_stock_threshold' => null, 'chart' => null, 'recent_orders' => null,
            'live_auctions' => $this->access->allows($user, 'auctions.view') ? DB::table('auctions')->where('status', 'live')->count() : null,
            // Both figures follow the viewer's branches (a branch-only grant never sees the company-wide count).
            'open_tickets' => $this->access->allows($user, 'support.view')
                ? $this->access->scope($user, 'support.view')->narrow($branchId)->apply(DB::table('support_tickets'), 'branch_id')->whereIn('status', ['open', 'pending_staff'])->count() : null,
            // Stock counts a colleague finished that wait for someone else to approve them (one open count per branch, so this is a handful at most).
            'stocktakes_waiting' => $this->access->allows($user, 'stocktaking.view')
                ? $this->access->scope($user, 'stocktaking.view')->narrow($branchId)->apply(DB::table('stocktaking_sessions'))->where('status', 'submitted')->count() : null,
            'pending_refunds' => $this->access->allows($user, 'refunds.view')
                ? DB::table('refunds')->whereIn('status', ['requested', 'approved'])
                    ->whereIn('order_id', $this->access->scope($user, 'refunds.view')->narrow($branchId)->apply(DB::table('order_fulfillments'), 'branch_id')->select('order_id'))->count() : null,
        ];

        if ($can['sales']) {
            $out = array_merge($out, $this->sales($salesScope));
        }
        if ($can['collected']) {
            $scope = $this->access->scope($user, $this->access->allows($user, 'payments.view') ? 'payments.view' : 'reports.view')->narrow($branchId);
            [$start, $end] = BusinessTime::dateRange(BusinessTime::now()->toDateString(), BusinessTime::now()->toDateString());
            $collected = SalesBasis::collected($scope, $start, $end);
            $out['collected_today'] = $collected['total']->format();
            $out['collected_online'] = $collected['online']->format();
            $out['collected_cash'] = $collected['cash']->format();
        }
        if ($can['orders']) {
            $orderScope = $this->access->scope($user, 'orders.view')->narrow($branchId);
            // A fulfillment waiting for the customer's online payment is not "to prepare": it cannot be prepared until it is paid.
            $out['pending_fulfillments'] = $orderScope->apply(DB::table('order_fulfillments as f')->join('orders as o', 'o.id', '=', 'f.order_id'), 'f.branch_id')
                ->whereIn('f.status', ['pending', 'accepted', 'preparing'])->where('o.status', '<>', 'pending_payment')->count();
            $out['recent_orders'] = DB::table('orders')->whereIn('id', $orderScope->apply(DB::table('order_fulfillments as f'), 'f.branch_id')->select('f.order_id'))
                ->orderByDesc('placed_at')->limit(8)->get(['id', 'number', 'customer_name', 'status', 'payment_status', 'grand_total', 'placed_at']);
        }
        if ($can['pickup']) {
            $out['ready_for_pickup'] = $this->access->scope($user, 'pickup.view')->narrow($branchId)->apply(DB::table('order_fulfillments'), 'branch_id')->where('status', 'pickup_ready')->count();
        }
        if ($can['deliveries']) {
            $out['out_for_delivery'] = $this->access->scope($user, 'deliveries.view')->narrow($branchId)->apply(DB::table('order_fulfillments'), 'branch_id')
                ->whereIn('status', ['dispatched', 'out_for_delivery'])->count();
        }
        if ($can['stock']) {
            $stockScope = $this->access->scope($user, 'inventory.view')->narrow($branchId);
            $counts = StockLevels::counts($stockScope->apply(DB::table('branch_inventory as bi'), 'bi.branch_id'));
            $out['low_stock'] = $counts['low'];
            $out['out_of_stock'] = $counts['out'];
            $out['low_stock_threshold'] = StockLevels::defaultThreshold();
        }

        return $out;
    }

    /**
     * Today's sales and the 14-day chart, from one query: the last point of the chart IS the tile, by construction.
     *
     * @return array<string, mixed>
     */
    private function sales(BranchScope $scope): array
    {
        $today = BusinessTime::now();
        $from = $today->subDays(13)->startOfDay()->utc();
        $series = SalesBasis::perDay($scope, $from, $today->endOfDay()->utc());

        $labels = [];
        $totals = [];
        $counts = [];
        for ($i = 13; $i >= 0; $i--) {
            $d = $today->subDays($i);
            $row = $series[$d->format('Y-m-d')] ?? null;
            $labels[] = $d->locale(app()->getLocale())->translatedFormat('d M');
            $totals[] = (float) ($row->total ?? 0);
            $counts[] = (int) ($row->orders ?? 0);
        }
        $todayRow = $series[$today->format('Y-m-d')] ?? null;

        return [
            'sales_today' => Money::of((string) ($todayRow->total ?? '0'))->format(),
            'orders_today' => (int) ($todayRow->orders ?? 0),
            'chart' => ['labels' => $labels, 'totals' => $totals, 'orders' => $counts],
        ];
    }
}
