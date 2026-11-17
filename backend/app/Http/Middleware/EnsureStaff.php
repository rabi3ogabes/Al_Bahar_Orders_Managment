<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Services\TwoFactorService;
use App\Support\DomainException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin/employee access policy, separate from customers: only active staff accounts pass.
 * For API tokens, also requires the "staff" ability so a customer token can never reach staff routes.
 * Staff with 2FA enabled must have completed the second factor in this web session; when
 * platform.auth.staff_two_factor_required is on, staff without 2FA may only open their profile to enrol.
 * An account whose password an administrator set (users.must_change_password) may only open its profile (dashboard) or read
 * `me` and change the password (staff API) until the person has chosen their own password.
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

        if (! $isApiToken && ! $user->hasTwoFactorEnabled() && app(TwoFactorService::class)->required()
            && ! $request->routeIs('admin.profile') && ! $request->hasHeader('X-Livewire')) {
            return redirect()->route('admin.profile')
                ->with('toast', ['type' => 'warning', 'message' => __('admin.two_factor_enrolment_required')]);
        }

        if ($user->must_change_password) {
            if ($isApiToken) {
                if (! $request->routeIs('api.v1.admin.me', 'api.v1.admin.me.password')) {
                    throw new DomainException('password_change_required', __('branch_access.error_password_change_required'), 403);
                }
            } elseif (! $this->mayChangePassword($request)) {
                if ($request->hasHeader('X-Livewire')) {
                    abort(403, __('branch_access.error_password_change_required'));
                }

                return redirect()->route('admin.profile')
                    ->with('toast', ['type' => 'warning', 'message' => __('branch_access.must_change_password_notice')]);
            }
        }

        return $next($request);
    }

    /** The profile page (and the actions inside it), signing out and the language switch. A Livewire call counts when it comes from the profile page. */
    private function mayChangePassword(Request $request): bool
    {
        if ($request->routeIs('admin.profile', 'admin.logout', 'admin.locale')) {
            return true;
        }

        return $request->hasHeader('X-Livewire')
            && parse_url((string) $request->headers->get('referer'), PHP_URL_PATH) === route('admin.profile', absolute: false);
    }
}
