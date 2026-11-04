<?php

namespace App\Domain\Reports\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Headline KPIs for the dashboard. Every figure is restricted to the viewer's authorized branches.
 */
class DashboardMetrics
{
    public function __construct(private readonly AccessControl $access) {}

    public function for(User $user, ?string $branchId = null): array
    {
        $orderScope = $this->access->scope($user, 'orders.view')->narrow($branchId);
        $stockScope = $this->access->scope($user, 'inventory.view')->narrow($branchId);
        $today = BusinessTime::now()->startOfDay()->utc();
        $from = BusinessTime::now()->subDays(13)->startOfDay()->utc();

        // Orders are branch-scoped through their fulfillments.
        $branchOrders = fn () => $orderScope->apply(DB::table('order_fulfillments'), 'order_fulfillments.branch_id')->select('order_id');

        $ordersToday = DB::table('orders')->whereIn('id', $branchOrders())->where('placed_at', '>=', $today);
        $revenueToday = (clone $ordersToday)->whereIn('payment_status', ['paid', 'partially_refunded'])->sum('grand_total');

        $series = $orderScope->apply(DB::table('order_fulfillments'), 'order_fulfillments.branch_id')
            ->join('orders', 'orders.id', '=', 'order_fulfillments.order_id')
            ->where('orders.placed_at', '>=', $from)
            ->whereNotIn('orders.status', ['cancelled'])
            ->selectRaw("to_char(orders.placed_at AT TIME ZONE ?, 'YYYY-MM-DD') as day, sum(order_fulfillments.subtotal) as total, count(distinct orders.id) as orders", [BusinessTime::zone()])
            ->groupBy('day')->orderBy('day')->get()->keyBy('day');

        $labels = [];
        $totals = [];
        $counts = [];
        for ($i = 13; $i >= 0; $i--) {
            $d = BusinessTime::now()->subDays($i);
            $key = $d->format('Y-m-d');
            $labels[] = $d->locale(app()->getLocale())->translatedFormat('d M');
            $totals[] = (float) ($series[$key]->total ?? 0);
            $counts[] = (int) ($series[$key]->orders ?? 0);
        }

        return [
            'orders_today' => (clone $ordersToday)->count(),
            'revenue_today' => Money::of((string) ($revenueToday ?: '0'))->format(),
            'pending_fulfillments' => $orderScope->apply(DB::table('order_fulfillments'), 'branch_id')
                ->whereIn('status', ['pending', 'accepted', 'preparing'])->count(),
            'ready_for_pickup' => $orderScope->apply(DB::table('order_fulfillments'), 'branch_id')->where('status', 'pickup_ready')->count(),
            'out_for_delivery' => $orderScope->apply(DB::table('order_fulfillments'), 'branch_id')->whereIn('status', ['dispatched', 'out_for_delivery'])->count(),
            'low_stock' => $stockScope->apply(DB::table('branch_inventory'), 'branch_id')
                ->whereRaw('(on_hand - reserved) <= minimum_stock')->where('minimum_stock', '>', 0)->count(),
            'out_of_stock' => $stockScope->apply(DB::table('branch_inventory'), 'branch_id')->whereRaw('(on_hand - reserved) <= 0')->count(),
            'live_auctions' => $this->access->allows($user, 'auctions.view') ? DB::table('auctions')->where('status', 'live')->count() : null,
            'open_tickets' => $this->access->allows($user, 'support.view') ? DB::table('support_tickets')->whereIn('status', ['open', 'pending_staff'])->count() : null,
            'pending_refunds' => $this->access->allows($user, 'refunds.view') ? DB::table('refunds')->whereIn('status', ['requested', 'approved'])->count() : null,
            'chart' => ['labels' => $labels, 'totals' => $totals, 'orders' => $counts],
            'recent_orders' => DB::table('orders')->whereIn('id', $branchOrders())->orderByDesc('placed_at')->limit(8)
                ->get(['id', 'number', 'customer_name', 'status', 'payment_status', 'grand_total', 'placed_at']),
        ];
    }
}
