<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Fulfillment\Services\PickupService;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The two pickup counter steps the branch staff app needs besides the ones of OrderOpsController (ready, verify, hand-over): a new pass for a pass that
 * expired or got lost, and the manager's hand-over without the pass. Thin: the rules, the permission checks (for the part's branch), the audit rows
 * and the lock after five wrong tries are all in {@see PickupService}.
 *
 * @tags Pickup
 */
class StaffPickupController extends ApiController
{
    /** A new pass for a part that is ready for pickup (or partly collected): the old one stops working at once and the customer is told. */
    public function reissue(Request $request, OrderFulfillment $fulfillment, PickupService $pickup): JsonResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:200']);
        $r = $pickup->reissue($fulfillment, $this->user(), $data['reason'] ?? null);

        return response()->json(['data' => ['status' => $r->status, 'token_expires_at' => $r->token_expires_at?->toIso8601String(), 'message' => __('staff_app_api.pickup.reissued')]]);
    }

    /**
     * A manager hands the goods over without the pass: the order number and the last four digits of the phone number on the order stand in for the QR.
     * `items` (order item id => quantity) and `collection` work exactly as in the scanner's hand-over; empty items hands over everything outstanding.
     */
    public function manualHandover(Request $request, PickupService $pickup): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => 'required|uuid', 'order_number' => 'required|string|max:40', 'phone_last4' => 'required|digits:4', 'reason' => 'nullable|string|max:500',
            'items' => 'nullable|array', 'items.*' => 'integer|min:0', 'collector_name' => 'nullable|string|max:120',
            'collection' => 'nullable|array', 'collection.tender' => 'required_with:collection|in:cash,card_terminal',
            'collection.amount' => 'required_with:collection|numeric|min:0', 'collection.receipt_reference' => 'nullable|string|max:120',
        ]);
        $r = $pickup->handoverByDetails($data['order_number'], $data['phone_last4'], $this->user(), $data['branch_id'], (string) ($data['reason'] ?? ''),
            $data['items'] ?? [], $data['collector_name'] ?? null, $data['collection'] ?? null);

        return response()->json(['data' => ['status' => $r->status, 'message' => __('staff_app_api.pickup.manual_done')]]);
    }
}
