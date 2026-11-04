<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->isCustomer() || ! $user->tokenCan('customer')) {
            abort(403, __('errors.forbidden'));
        }
        if (! $user->isActive()) {
            abort(403, __('errors.account_inactive'));
        }

        return $next($request);
    }
}
