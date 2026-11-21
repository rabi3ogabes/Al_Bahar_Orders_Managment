<?php

namespace App\Domain\Search\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Search\Support\SearchGroup;
use App\Domain\Search\Support\SearchQuery;
use App\Support\Money;

/**
 * Orders for the global search: by order number (or any part of it, or the number of one branch part) and by the customer's phone.
 *
 * One query, bounded by LIMIT and served by the trigram indexes on orders.number and orders.customer_phone. Branch-scoped staff only get
 * orders that have a part in one of their branches (the same rule as the Orders list); what is shown about each order is what that list shows.
 */
final class OrderResults
{
    public function __construct(private readonly AccessControl $acl) {}

    public function find(User $user, SearchQuery $query): ?SearchGroup
    {
        $scope = $this->acl->scope($user, 'orders.view');
        if ($scope->isEmpty() || ! $query->searchesTables() || ($query->order === null && $query->phone === null)) {
            return null;
        }

        $q = Order::query()
            ->select(['orders.id', 'orders.number', 'orders.status', 'orders.grand_total', 'orders.currency', 'orders.customer_name', 'orders.customer_phone'])
            ->where(function ($w) use ($query) {
                if ($query->order !== null) {
                    $w->orWhere('orders.number', 'ilike', SearchQuery::contains($query->order));
                }
                if ($query->phone !== null) {
                    // "+974 5512" starts the number; "5512" is a piece of it.
                    $w->orWhere('orders.customer_phone', 'like', $query->phoneHasCountryCode ? SearchQuery::startsWith('+974'.$query->phone) : SearchQuery::contains($query->phone));
                }
            });
        if (! $scope->all) {
            $q->whereIn('orders.id', $scope->apply(OrderFulfillment::query()->select('order_id')->getQuery(), 'branch_id'));
        }
        // The order whose number is exactly what was typed comes first, then the newest.
        $exact = $query->order === null ? ['', ''] : ['AB'.$query->order, $query->order];
        $rows = $q->orderByRaw('case when orders.number in (?, ?) then 0 else 1 end', $exact)->orderByDesc('orders.placed_at')->orderBy('orders.id')
            ->limit(SearchGroup::LIMIT + 1)->get();
        if ($rows->isEmpty()) {
            return null;
        }

        $items = $rows->take(SearchGroup::LIMIT)->map(fn (Order $o) => [
            'id' => (string) $o->id,
            'title' => $o->number,
            'subtitle' => trim($o->customer_name.($o->customer_phone ? ' · '.SearchGroup::ltr($o->customer_phone) : '')),
            'badge' => __('status.'.$o->status->value),
            'url' => route('admin.orders.index', ['view' => $o->id]),
            'icon' => 'bag',
            'ltr' => true,
            'meta' => Money::of((string) $o->grand_total, $o->currency ?: 'QAR')->format(),
        ])->values()->all();

        return new SearchGroup('orders', __('search.group.orders'), $items, $rows->count() > SearchGroup::LIMIT
            ? ['label' => __('search.see_all.orders'), 'url' => route('admin.orders.index', ['search' => $query->raw])] : null);
    }
}
