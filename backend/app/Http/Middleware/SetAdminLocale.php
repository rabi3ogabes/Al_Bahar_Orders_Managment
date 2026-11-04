<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Admin dashboard locale: session choice → staff user preference → default. */
class SetAdminLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('platform.supported_locales');
        $locale = $request->session()->get('locale') ?? $request->user()?->locale ?? config('app.locale');
        app()->setLocale(in_array($locale, $supported, true) ? $locale : config('app.locale'));

        return $next($request);
    }
}
