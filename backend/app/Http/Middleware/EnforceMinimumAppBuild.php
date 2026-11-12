<?php

namespace App\Http\Middleware;

use App\Domain\AppUpdates\Models\AppRelease;
use App\Domain\AppUpdates\Services\AppUpdateService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The server side of "force update": a store app that sends X-App / X-App-Platform / X-App-Build and is older than the
 * minimum build gets HTTP 426 on every API call (except the update check itself), so a forced update cannot be bypassed.
 * Requests without these headers (the website, partners, webhooks, older apps that never sent them) are not affected.
 */
class EnforceMinimumAppBuild
{
    public function __construct(private readonly AppUpdateService $updates) {}

    public function handle(Request $request, Closure $next): Response
    {
        $app = strtolower((string) $request->header('X-App'));
        $platform = strtolower((string) $request->header('X-App-Platform'));
        $build = (int) $request->header('X-App-Build');
        if ($build <= 0 || ! in_array($app, AppRelease::APPS, true) || ! in_array($platform, AppRelease::PLATFORMS, true) || $request->is('api/v1/app-update')) {
            return $next($request);
        }
        $verdict = $this->updates->evaluate($app, $platform, $build);
        if ($verdict['status'] !== 'required') {
            return $next($request);
        }

        return response()->json(['code' => 'update_required', 'message' => $verdict['message'][app()->getLocale() === 'ar' ? 'ar' : 'en'], 'update' => $verdict], 426);
    }
}
