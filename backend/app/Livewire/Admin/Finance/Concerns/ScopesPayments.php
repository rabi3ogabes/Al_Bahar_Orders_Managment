<?php

namespace App\Livewire\Admin\Finance\Concerns;

use App\Domain\Identity\Support\BranchScope;
use App\Domain\Orders\Models\OrderFulfillment;
use Illuminate\Database\Eloquent\Builder;

/** Payment-owned records (payments, refunds) reach a branch through their order's fulfillments. */
trait ScopesPayments
{
    protected function scopeByOrder(Builder $q, BranchScope $scope, string $orderColumn = 'order_id'): Builder
    {
        if ($scope->all) {
            return $q;
        }

        return $q->whereIn($orderColumn, $scope->apply(OrderFulfillment::query()->select('order_id')->getQuery(), 'branch_id'));
    }
}
