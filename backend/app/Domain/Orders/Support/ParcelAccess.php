<?php

namespace App\Domain\Orders\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Support\BranchScope;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\SubstitutionRequest;

/**
 * Who may read which PARCEL (branch part) of a split order (roadmap 4.6).
 *
 * An order is listed for, and opens for, every branch that holds a part of it; what a branch-limited staff member reads inside it is THEIR branches' parts
 * only: the parcels, their lines, deliveries and pickups, the item changes, replacement questions, claims, notes and timeline entries that belong to a
 * parcel of another branch are never sent to them. A viewer with access to every branch sees everything. The order itself (customer, totals, payments)
 * stays whole: it is one sale, and the branch needs it to serve the customer.
 *
 * The check is made on the server where the data is read (the dashboard order screens and the staff API), never in the view.
 */
final class ParcelAccess
{
    /** The branches whose parcels the person may read (their orders.view scope, not narrowed by any filter on the screen). */
    public static function scope(?User $user): BranchScope
    {
        return app(AccessControl::class)->scope($user, 'orders.view');
    }

    /**
     * An order narrowed to the parcels the scope allows: its fulfillments, lines, item changes, notes and timeline. The relations are replaced on the
     * model in memory (nothing is saved), so everything that reads them afterwards sees only the allowed parcels.
     */
    public static function narrow(Order $order, BranchScope $scope): Order
    {
        if ($scope->all) {
            return $order;
        }
        $order->loadMissing(['fulfillments', 'items', 'revisions', 'notes', 'history']);
        $ids = $order->fulfillments->filter(fn ($f) => $scope->contains($f->branch_id))->pluck('id')->all();
        $order->setRelation('fulfillments', $order->fulfillments->filter(fn ($f) => $scope->contains($f->branch_id))->values());
        $order->setRelation('items', $order->items->filter(fn ($i) => $scope->contains($i->branch_id))->values());
        $order->setRelation('revisions', $order->revisions->filter(fn ($r) => $scope->contains($r->branch_id))->values());
        $order->setRelation('notes', $order->notes->filter(fn ($n) => $n->order_fulfillment_id === null || in_array($n->order_fulfillment_id, $ids, true))->values());
        $order->setRelation('history', $order->history->filter(fn ($h) => $h->order_fulfillment_id === null || in_array($h->order_fulfillment_id, $ids, true))->values());

        return $order;
    }

    /**
     * The staff API's order detail without the parts of other branches: lines, parcels, replacement questions and claims outside the scope are dropped,
     * and the customer's live order card (which speaks for the least advanced part of the whole order) is left out.
     *
     * @param  array<string, mixed>  $detail  OrderPresenter::detail() of an order that was narrowed with narrow()
     * @return array<string, mixed>
     */
    public static function detail(array $detail, BranchScope $scope): array
    {
        if ($scope->all) {
            return $detail;
        }
        $allowed = $scope->branchIds === [] ? collect() : SubstitutionRequest::where('order_id', $detail['id'])->whereIn('branch_id', $scope->branchIds)->pluck('id');
        $detail['substitution_requests'] = array_values(array_filter((array) ($detail['substitution_requests'] ?? []), fn (array $s) => $allowed->contains($s['id'])));
        $detail['waiting_for_customer'] = collect($detail['substitution_requests'])->contains(fn (array $s) => ($s['status'] ?? null) === 'waiting');
        $returns = array_values(array_filter((array) ($detail['returns'] ?? []), fn (array $r) => isset($r['branch']['id']) && $scope->contains($r['branch']['id'])));
        $detail['returns'] = $returns;
        $detail['return_request'] = $returns[0] ?? null;
        $detail['progress'] = null;

        return $detail;
    }
}
