<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin/employee access policy, separate from customers: only active staff accounts pass.
 * For API tokens, also requires the "staff" ability so a customer token can never reach staff routes.
 * Staff with 2FA enabled must have completed the second factor in this web session.
 */
class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        $isApiToken = $token && method_exists($token, 'can');

        if (! $user || ! $user->isStaff() || ! $user->isActive() || ($isApiToken && ! $token->can('staff'))) {
            if ($request->expectsJson()) {
                abort(403, __('errors.forbidden'));
            }
            if ($user && ! $user->isStaff()) {
                auth()->guard('web')->logout();
            }

            return redirect()->route('admin.login');
        }

        if (! $isApiToken && $user->hasTwoFactorEnabled() && ! $request->session()->get('two_factor_passed')) {
            return redirect()->route('admin.two-factor.challenge');
        }

        return $next($request);
    }
}
