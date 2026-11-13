<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Public, key-only settings a browser needs to open the live socket (Laravel Reverb, Pusher protocol). The app key is a
 * public identifier, never a secret. The socket is reached on the same host the page was loaded from (the web server
 * proxies /app to Reverb), so only the key and the channel auth route are returned. `enabled` is false when no key is set.
 */
class RealtimeConfigController extends Controller
{
    public function show(): JsonResponse
    {
        $key = (string) config('reverb.apps.apps.0.key', '');

        return response()->json(['data' => [
            'enabled' => $key !== '',
            'key' => $key !== '' ? $key : null,
            'auth_endpoint' => url('/api/v1/broadcasting/auth'),
            'path' => '/app',
        ]])->header('Cache-Control', 'public, max-age=300');
    }
}
