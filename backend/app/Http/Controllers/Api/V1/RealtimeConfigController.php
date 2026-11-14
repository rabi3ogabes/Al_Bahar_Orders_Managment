<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public, key-only settings a browser needs to open the live socket (Laravel Reverb, Pusher protocol). The app key is a
 * public identifier, never a secret. The socket is reached on the same host the page was loaded from (the web server
 * proxies /app to Reverb), so only the key and the channel auth route are returned. `enabled` is false when no key is set.
 *
 * The phone apps read the same answer instead of carrying a socket address in their build: `socket` says where the live socket is
 * (scheme, host, port, path) as this server is reached right now, so one app build works on every environment and a move to another
 * domain needs no new release. `poll_seconds` is how often an app re-reads while the socket is down. Public: nothing secret in it.
 */
class RealtimeConfigController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $key = (string) config('reverb.apps.apps.0.key', '');
        $secure = $request->isSecure();

        return response()->json(['data' => [
            'enabled' => $key !== '',
            'key' => $key !== '' ? $key : null,
            'auth_endpoint' => url('/api/v1/broadcasting/auth'),
            'path' => '/app',
            'socket' => ['scheme' => $secure ? 'wss' : 'ws', 'host' => $request->getHost(), 'port' => $request->getPort() ?: ($secure ? 443 : 80), 'path' => '/app'],
            'poll_seconds' => 15,
        ]])->header('Cache-Control', 'public, max-age=300');
    }
}
