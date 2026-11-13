<?php

namespace App\Http\Controllers;

use App\Domain\Notifications\Services\FirebaseConfig;
use App\Support\FirebaseWorkerScript;
use Illuminate\Http\Response;

/**
 * Serves the Firebase messaging service worker with the public web settings the owner saved in the dashboard. Two URLs, one script:
 * the store (and the Firebase SDK's default) asks for /firebase-messaging-sw.js, the Flutter web app for /mobile-web/firebase-messaging-sw.js.
 * The file has to come from the site itself with a JavaScript type, and it must never be cached, or a changed key would not reach browsers.
 */
class FirebaseWorkerController extends Controller
{
    public function show(FirebaseConfig $config): Response
    {
        return response(FirebaseWorkerScript::render($config->publicConfig()['web']), 200, [
            'Content-Type' => 'text/javascript; charset=UTF-8',
            'Service-Worker-Allowed' => '/',
            'Cache-Control' => 'no-cache, max-age=0, must-revalidate',
        ]);
    }
}
