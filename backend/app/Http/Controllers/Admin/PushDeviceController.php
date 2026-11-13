<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Services\DeviceRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The dashboard's "Enable on this browser": stores the web push token of the signed-in staff member's browser (session login, CSRF protected). */
class PushDeviceController extends Controller
{
    public function store(Request $request, DeviceRegistry $devices): JsonResponse
    {
        $data = $request->validate(['push_token' => ['required', 'string', 'min:20', 'max:512']]);
        $device = $devices->register($request->user(), [
            'platform' => 'web', 'push_token' => $data['push_token'], 'app' => 'admin', 'locale' => app()->getLocale(),
        ], $request->userAgent());
        app(AuditLogger::class)->log('push.browser_enabled', $device, [], ['browser' => $device->browser]);

        return response()->json(['data' => ['id' => $device->id, 'browser' => $device->browser, 'registered' => true]], 201);
    }
}
