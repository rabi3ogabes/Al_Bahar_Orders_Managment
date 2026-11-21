<?php

namespace App\Http\Controllers\Api\V1\Commerce;

use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\OrderPresenter;
use App\Domain\Orders\Services\SubstitutionService;
use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer's answer to "We do not have X. Replace it with Y?". Only the order's owner can answer (any other order or question answers 404),
 * repeating the same answer is harmless, and everything that matters (the price, the deadline, the fallback) is the server's, never the client's.
 *
 * @tags Orders
 */
class SubstitutionController extends ApiController
{
    public function answer(Request $request, SubstitutionService $substitutions, OrderPresenter $presenter, string $id, string $requestId): JsonResponse
    {
        $data = $request->validate(['answer' => 'required|in:accept,decline']);
        $order = Order::where('user_id', $this->user()->id)->findOrFail($id);
        $result = $substitutions->answer($order, $this->user(), $requestId, $data['answer']);

        return response()->json(['data' => [
            'status' => $result['request']->status, 'replayed' => $result['replayed'], 'order' => $presenter->detail($order->fresh()),
        ]]);
    }
}
