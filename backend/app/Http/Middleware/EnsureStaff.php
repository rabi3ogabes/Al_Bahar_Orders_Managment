<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Services\TwoFactorService;
use App\Domain\Operations\Services\WallDisplaySession;
use App\Support\DomainException;
use Closure;
use Illuminate\Http\Request;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin/employee access policy, separate from customers: only active staff accounts pass.
 * For API tokens, also requires the "staff" ability so a customer token can never reach staff routes.
 * Staff with 2FA enabled must have completed the second factor in this web session; when
 * platform.auth.staff_two_factor_required is on, staff without 2FA may only open their profile to enrol.
 * An account whose password an administrator set (users.must_change_password) may only open its profile (dashboard) or read
 * `me` and change the password (staff API) until the person has chosen their own password.
 * A dashboard session must carry the person's current users.session_stamp (rotated whenever their sessions are revoked), so an old
 * browser cookie is refused whatever the session driver and even after the account is switched on again.
 * Both "first step" gates (password, two-factor enrolment) look at the REAL route, and at the signed Livewire snapshots of a Livewire
 * call, never at request headers: a client cannot claim to be the profile page.
 * A wall display account (its only right is board.view, see AccessControl::isWallDisplay) is confined here, whatever any page would say: it may open the live
 * board, refresh it, join its live channels and sign out, and nothing else (any other page, the search, the profile and the whole staff API answer 403).
 * It is signed out once it has not been seen for the configured number of hours (WallDisplaySession). It has no profile to open, so the first-step gates do not apply to it.
 */
class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        $isApiToken = $token && method_exists($token, 'can');

        $stale = $user && $user->isStaff() && ! $isApiToken && ! $this->sessionIsCurrent($request, $user);
        if (! $user || ! $user->isStaff() || ! $user->isActive() || ($isApiToken && ! $token->can('staff')) || $stale) {
            if ($stale || ($user && ! $user->isStaff())) {
                auth()->guard('web')->logout();
            }
            if ($request->expectsJson()) {
                abort(403, __('errors.forbidden'));
            }

            return redirect()->route('admin.login');
        }

        if (! $isApiToken && $user->hasTwoFactorEnabled() && ! $request->session()->get('two_factor_passed')) {
            return redirect()->route('admin.two-factor.challenge');
        }

        if (app(AccessControl::class)->isWallDisplay($user)) {
            return $this->confineToTheWall($request, $next, $user, $isApiToken);
        }

        if (! $isApiToken && ! $user->hasTwoFactorEnabled() && app(TwoFactorService::class)->required() && ! $this->isProfileRequest($request)) {
            return $this->sendToProfile($request, __('admin.two_factor_enrolment_required'), __('admin.two_factor_enrolment_required'));
        }

        if ($user->must_change_password) {
            if ($isApiToken) {
                if (! $request->routeIs('api.v1.admin.me', 'api.v1.admin.me.password')) {
                    throw new DomainException('password_change_required', __('branch_access.error_password_change_required'), 403);
                }
            } elseif (! $this->isProfileRequest($request)) {
                return $this->sendToProfile($request, __('branch_access.must_change_password_notice'), __('branch_access.error_password_change_required'));
            }
        }

        return $next($request);
    }

    /**
     * The wall display account: the live board only. Checked on the real route and, for a Livewire call, on the signed snapshots (every component must be the
     * board), like the first-step gates. Past its time the account is signed out and the screen is sent to the sign-in page.
     */
    private function confineToTheWall(Request $request, Closure $next, User $user, bool $isApiToken): Response
    {
        if ($isApiToken) {
            throw new DomainException('wall_display_only', __('wall_display.api_refused'), 403);
        }
        if (! app(WallDisplaySession::class)->touch($user)) {
            auth()->guard('web')->logout();
            $request->session()->invalidate();

            if ($this->isLivewireUpdate() || $request->expectsJson()) {
                abort(419); // "page expired": the board reloads and lands on the sign-in page
            }

            return redirect()->route('admin.login');
        }
        if (! $this->isWallRequest($request)) {
            abort(403, __('errors.forbidden'));
        }

        return $next($request);
    }

    private function isWallRequest(Request $request): bool
    {
        if ($this->isLivewireUpdate()) {
            $names = [];
            foreach ((array) $request->input('components', []) as $component) {
                $snapshot = is_array($component) && is_string($component['snapshot'] ?? null) ? json_decode($component['snapshot'], true) : null;
                $names[] = is_array($snapshot) ? ($snapshot['memo']['name'] ?? null) : null;
            }

            return $names !== [] && array_unique($names) === ['admin.operations.live-board-page'] && $request->routeIs('admin.operations.board');
        }

        return $request->routeIs('admin.operations.board', 'admin.logout', 'admin.locale') || $request->is('broadcasting/auth');
    }

    /** A page goes to the profile with a notice; a Livewire call is simply refused (it is not the profile page's own action). */
    private function sendToProfile(Request $request, string $notice, string $refusal): Response
    {
        if ($this->isLivewireUpdate()) {
            abort(403, $refusal);
        }

        return redirect()->route('admin.profile')->with('toast', ['type' => 'warning', 'message' => $notice]);
    }

    private function isLivewireUpdate(): bool
    {
        return app(HandleRequests::class)->isLivewireRoute();
    }

    /**
     * The profile page, signing out and the language switch: what a person may still do before their first step is finished.
     * A Livewire call counts only when EVERY component in it is the profile component (names come from the signed snapshots, which
     * Livewire verifies before it runs anything) and its page was the profile page. Headers play no part.
     */
    private function isProfileRequest(Request $request): bool
    {
        if ($this->isLivewireUpdate()) {
            $names = [];
            foreach ((array) $request->input('components', []) as $component) {
                $snapshot = is_array($component) && is_string($component['snapshot'] ?? null) ? json_decode($component['snapshot'], true) : null;
                $names[] = is_array($snapshot) ? ($snapshot['memo']['name'] ?? null) : null;
            }

            return $names !== [] && array_unique($names) === ['admin.profile'] && $request->routeIs('admin.profile');
        }

        return $request->routeIs('admin.profile', 'admin.logout', 'admin.locale');
    }

    /**
     * Does this browser session carry the account's current stamp? A session that carries none was started by a "remember me" cookie
     * (the cookie itself is only accepted while remember_token is unchanged, and revoking cycles it), so it adopts the current stamp.
     */
    private function sessionIsCurrent(Request $request, User $user): bool
    {
        if (! $request->hasSession()) {
            return true; // not a browser session (nothing to compare)
        }
        $stamp = (string) $user->session_stamp;
        $session = $request->session();
        if (! $session->has('session_stamp') && auth()->guard('web')->viaRemember()) {
            $session->put('session_stamp', $stamp);
        }

        return hash_equals($stamp, (string) $session->get('session_stamp', ''));
    }
}
