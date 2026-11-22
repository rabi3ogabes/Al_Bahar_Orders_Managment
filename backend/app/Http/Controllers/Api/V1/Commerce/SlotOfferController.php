<?php

namespace App\Http\Controllers\Api\V1\Commerce;

use App\Domain\Fulfillment\Services\SlotOffers;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\OrderPresenter;
use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer's answer to "We could not deliver. Choose a new time": picks one of the times offered after a failed delivery.
 * Only the order's owner can answer (any other order, offer or customer answers 404), the same Idempotency-Key answers the same way and does nothing twice,
 * and what matters (which times were offered, whether one is still free, the deadline) is the server's, never the client's.
 *
 * @tags Orders
 */
class SlotOfferController extends ApiController
{
    public function choose(Request $request, SlotOffers $offers, OrderPresenter $presenter, string $id, string $offerId): JsonResponse
    {
        $order = Order::where('user_id', $this->user()->id)->findOrFail($id);
        $key = $this->idempotencyKey($request);
        $data = $request->validate(['slot_id' => 'required|uuid', 'date' => 'required|date_format:Y-m-d']);
        $result = $offers->choose($order, $this->user(), $offerId, $data['slot_id'], $data['date'], $key);

        return response()->json(['data' => ['status' => $result['offer']->status, 'replayed' => $result['replayed'], 'order' => $presenter->detail($order->fresh())]]);
    }
}
