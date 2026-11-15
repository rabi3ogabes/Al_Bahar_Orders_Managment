<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Services\OrderAdjustmentService;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Admin\AdjustOrderItemsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Missing items found while preparing: reduce, replace or remove (docs/api/ORDER_ADJUSTMENTS.md). Staff with orders.edit on the fulfillment's branch only;
 * the service re-checks the branch for the specific fulfillment.
 *
 * @tags Admin · Orders
 */
class OrderAdjustmentController extends ApiController
{
    public function show(OrderFulfillment $fulfillment, OrderAdjustmentService $adjustments): JsonResponse
    {
        return response()->json(['data' => $adjustments->context($fulfillment, $this->user())]);
    }

    public function substitutes(Request $request, OrderFulfillment $fulfillment, OrderAdjustmentService $adjustments): JsonResponse
    {
        $q = $request->validate(['search' => 'nullable|string|max:100', 'limit' => 'nullable|integer|min:1|max:50']);

        return response()->json(['data' => $adjustments->substitutes($fulfillment, $this->user(), (string) ($q['search'] ?? ''), (int) ($q['limit'] ?? 20))]);
    }

    public function store(AdjustOrderItemsRequest $request, OrderFulfillment $fulfillment, OrderAdjustmentService $adjustments): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['data' => $adjustments->adjust($fulfillment, $this->user(), $request->changes(), $data['reason_code'], $data['note'] ?? null, (bool) ($data['preview'] ?? false))]);
    }
}
