<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Notifications\Services\FirebaseConfig;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    /**
     * With ?platform=android|ios&app=customer|driver|staff the answer also carries `native`: the Firebase settings that phone app needs to
     * start (null when push is off or that app has none yet).
     */
    public function show(Request $request, FirebaseConfig $config): JsonResponse
    {
        $platform = $request->query('platform');
        $app = $request->query('app');
        $platform = in_array($platform, array_keys(FirebaseConfig::NATIVE_APPS), true) ? $platform : null;
        $app = in_array($app, array_keys(FirebaseConfig::PACKAGES), true) ? $app : null;

        return response()->json(['data' => $config->publicConfig($platform, $app)])->header('Cache-Control', 'public, max-age=300');
    }
}
