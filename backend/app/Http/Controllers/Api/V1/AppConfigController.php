<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Settings\Services\Settings;
use App\Http\Controllers\Controller;
use App\Support\AlternatePhone;
use App\Support\IntroSlides;
use Illuminate\Http\JsonResponse;

/**
 * Small public settings the customer apps and the web store need before anyone signs in: the "Designed by" line of the intro screens
 * (edited in Settings → General & brand; an empty text means "do not show"), the words of the three intro slides (same page, "Intro screens":
 * a text is the admin's own or null, and null means "use the app's own words"), whether customers may return orders, whether the alternative
 * phone number is asked for, and how the staff and driver apps ask for an order status change (Settings → Checkout & fees: buttons or slide
 * to confirm). No secrets.
 * Cached one minute at every layer: the dashboard drops its own settings cache on every save, and the answer is `public, max-age=60`, so a
 * change reaches a phone within a minute of it asking (the app asks at launch and whenever it comes back to the foreground).
 *
 * @tags App
 */
class AppConfigController extends Controller
{
    /** The stored style, or `buttons` for anything that is not one of the two (an old or hand-edited value never breaks the apps). */
    public static function statusStyle(Settings $settings): string
    {
        $style = $settings->get('ops.status_style', 'buttons');

        return is_string($style) && in_array($style, ['buttons', 'slide'], true) ? $style : 'buttons';
    }

    public function show(Settings $settings): JsonResponse
    {
        $line = fn (string $key): string => trim((string) $settings->get($key, ''));

        return response()->json(['data' => [
            'designed_by' => ['en' => $line('customer_app.designed_by_en'), 'ar' => $line('customer_app.designed_by_ar')],
            'intro' => IntroSlides::forApi($settings),
            'returns_enabled' => (bool) $settings->get('returns.enabled', true),
            'alternate_phone_enabled' => (bool) $settings->get(AlternatePhone::SETTING, true),
            'ops' => ['status_style' => self::statusStyle($settings)],
        ]])->header('Cache-Control', 'public, max-age=60');
    }
}
