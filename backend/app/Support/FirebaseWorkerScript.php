<?php

namespace App\Support;

use App\Domain\Settings\Services\Settings;

/**
 * The service worker the browsers load from /firebase-messaging-sw.js and /mobile-web/firebase-messaging-sw.js. The template
 * (resources/push/firebase-messaging-sw.js) is plain JavaScript; the public Firebase web settings are injected as one JSON value, escaped
 * so that no setting can break out of the script (< > & ' " and every non-ASCII character are written as \u escapes).
 */
final class FirebaseWorkerScript
{
    /** The Firebase compat bundles the worker imports from gstatic.com (a released version, so the file never changes underneath us). */
    public const SDK_VERSION = '10.14.1';

    /** Shown when a push carries no icon of its own (the app icon the Flutter web build ships). */
    public const ICON = '/mobile-web/icons/Icon-192.png';

    private const MARKER = '/*@settings*/ {}';

    public static function render(?array $web): string
    {
        if ($web === null) {
            // Not set up (or switched off): a worker that does nothing, so a registration made earlier simply goes quiet.
            return "/* Push notifications are not set up. */\nself.addEventListener('install', function () { self.skipWaiting(); });\nself.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });\n";
        }
        $settings = [
            'sdk' => self::SDK_VERSION,
            'brand' => (string) app(Settings::class)->get('brand.name_en', config('app.name')),
            'icon' => self::ICON,
            'firebase' => [
                'apiKey' => $web['apiKey'], 'authDomain' => $web['authDomain'], 'projectId' => $web['projectId'],
                'messagingSenderId' => $web['messagingSenderId'], 'appId' => $web['appId'],
            ],
        ];
        $json = json_encode($settings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return str_replace(self::MARKER, $json, (string) file_get_contents(resource_path('push/firebase-messaging-sw.js')));
    }
}
