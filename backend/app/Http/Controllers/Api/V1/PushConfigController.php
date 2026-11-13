<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Notifications\Services\FirebaseConfig;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Public Firebase web settings for the store and the web version of the mobile app, so a browser can ask for notification
 * permission and register for push. Everything returned is meant for browsers (the API key and the Web Push public key are not secrets).
 * Nothing is returned while push is switched off or the server key is missing; the service account never leaves the server.
 * Cached five minutes at every layer.
 *
 * @tags Push
 */
class PushConfigController extends Controller
{
    public function show(FirebaseConfig $config): JsonResponse
    {
        return response()->json(['data' => $config->publicConfig()])->header('Cache-Control', 'public, max-age=300');
    }
}
