<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** API locale: ?lang → X-Locale → Accept-Language → authenticated user's preference → default. */
class SetApiLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('platform.supported_locales');
        $candidates = [
            $request->query('lang'),
            $request->header('X-Locale'),
            $request->getPreferredLanguage($supported),
            $request->user()?->locale,
        ];
        $locale = config('app.locale');
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && in_array(substr($candidate, 0, 2), $supported, true)) {
                $locale = substr($candidate, 0, 2);
                break;
            }
        }
        app()->setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }
}
