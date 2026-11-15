<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Domain\Notifications\Services\LiveActivityRegistry;
use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The iPhone app reports its Live Activity tokens here (the order on the lock screen and in the Dynamic Island): the push token of a
 * running activity for one order, and the phone's push-to-start token. The server then updates and ends the activity through Apple.
 * Only the customer's own orders are accepted; a token of somebody else is never touched.
 *
 * @tags Account
 */
class LiveActivityController extends ApiController
{
    /** A token Apple made: hexadecimal, a few dozen to a couple of hundred characters. */
    private const TOKEN = ['required', 'string', 'regex:/^[0-9a-fA-F]{32,250}$/'];

    /** Reports the push token of a running Live Activity for one of the caller's orders (again on every token rotation). */
    public function store(Request $request, LiveActivityRegistry $registry): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'uuid'],
            'push_token' => self::TOKEN,
            'environment' => ['nullable', 'in:production,sandbox'],
            'device_id' => ['nullable', 'string', 'max:191'],
            'locale' => ['nullable', 'in:en,ar'],
            'app_version' => ['nullable', 'string', 'max:32'],
        ]);
        $token = $registry->registerActivity($this->user(), $data); // 404 for an order that is not the caller's

        return response()->json(['data' => ['id' => $token->id, 'registered' => true]], 201);
    }

    /** Reports the phone's push-to-start token (iOS 17.2+), which lets the server start the activity while the app is closed. */
    public function storeStartToken(Request $request, LiveActivityRegistry $registry): JsonResponse
    {
        $data = $request->validate([
            'push_token' => self::TOKEN,
            'environment' => ['nullable', 'in:production,sandbox'],
            'device_id' => ['nullable', 'string', 'max:191'],
            'locale' => ['nullable', 'in:en,ar'],
            'app_version' => ['nullable', 'string', 'max:32'],
        ]);
        $token = $registry->registerStart($this->user(), $data);

        return response()->json(['data' => ['id' => $token->id, 'registered' => true]], 201);
    }

    /**
     * Stops reporting: one token, the activities of one order, or everything this phone reported (sign-out). At least one of the three.
     * Only the caller's own tokens are switched off.
     */
    public function destroy(Request $request, LiveActivityRegistry $registry): JsonResponse
    {
        $data = $request->validate([
            'push_token' => ['nullable', 'string', 'max:250'],
            'order_id' => ['nullable', 'uuid'],
            'device_id' => ['nullable', 'string', 'max:191'],
        ]);
        if (empty($data['push_token']) && empty($data['order_id']) && empty($data['device_id'])) {
            return response()->json(['message' => __('validation.required', ['attribute' => 'push_token']), 'errors' => ['push_token' => [__('validation.required', ['attribute' => 'push_token'])]]], 422);
        }
        $registry->disable($this->user(), $data);

        return response()->json(['data' => ['unregistered' => true]]);
    }
}
