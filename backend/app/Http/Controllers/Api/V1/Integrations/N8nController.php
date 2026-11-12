<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Domain\Marketing\Services\JourneyService;
use App\Domain\Notifications\Services\N8nClient;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** n8n tells us what happened to a WhatsApp message it sent for a journey. Signed with the shared secret; unknown fields are ignored. */
class N8nController extends Controller
{
    public function delivery(Request $request, N8nClient $n8n, JourneyService $journeys): JsonResponse
    {
        $body = $request->getContent();
        if (! $n8n->verify((string) $request->header('X-Bahar-Timestamp'), $body, (string) $request->header('X-Bahar-Signature'))) {
            return response()->json(['message' => 'invalid_signature'], 401);
        }
        $data = validator($request->json()->all(), [
            'delivery_id' => ['required', 'uuid'], 'status' => ['required', 'in:sent,delivered,failed'],
            'external_id' => ['nullable', 'string', 'max:96'], 'reason' => ['nullable', 'string', 'max:200'],
        ])->validate();

        return response()->json(['ok' => $journeys->recordProviderStatus($data['delivery_id'], $data['status'], $data['external_id'] ?? null, $data['reason'] ?? null)]);
    }
}
