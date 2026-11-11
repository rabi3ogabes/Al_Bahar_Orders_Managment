<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin/employee access policy, separate from customers: only active staff accounts pass.
 * For API tokens, also requires the "staff" ability so a customer token can never reach staff routes.
 * Staff with 2FA enabled must have completed the second factor in this web session; when
 * platform.auth.staff_two_factor_required is on, staff without 2FA may only open their profile to enrol.
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

        if (! $isApiToken && ! $user->hasTwoFactorEnabled() && config('platform.auth.staff_two_factor_required')
            && ! $request->routeIs('admin.profile') && ! $request->hasHeader('X-Livewire')) {
            return redirect()->route('admin.profile')
                ->with('toast', ['type' => 'warning', 'message' => __('admin.two_factor_enrolment_required')]);
        }

        return $next($request);
    }
}
