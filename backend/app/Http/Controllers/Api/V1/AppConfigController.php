<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Settings\Services\Settings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Small public settings the customer apps and the web store need before anyone signs in: the "Designed by" line of the intro screens
 * (edited in Settings → General & brand; an empty text means "do not show") and whether customers may return orders. No secrets.
 * Cached one minute at every layer.
 *
 * @tags App
 */
class AppConfigController extends Controller
{
    public function show(Settings $settings): JsonResponse
    {
        $line = fn (string $key): string => trim((string) $settings->get($key, ''));

        return response()->json(['data' => [
            'designed_by' => ['en' => $line('customer_app.designed_by_en'), 'ar' => $line('customer_app.designed_by_ar')],
            'returns_enabled' => (bool) $settings->get('returns.enabled', true),
        ]])->header('Cache-Control', 'public, max-age=60');
    }
}
