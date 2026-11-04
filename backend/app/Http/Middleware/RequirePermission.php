<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Services\AccessControl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level coarse check: `permission:orders.view`. The user must hold the permission on at least one
 * branch. Record-level branch checks are still performed by policies/BranchScope in controllers.
 */
class RequirePermission
{
    public function __construct(private readonly AccessControl $access) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        foreach ($permissions as $permission) {
            if ($this->access->allows($request->user(), $permission)) {
                return $next($request);
            }
        }
        abort(403, __('errors.forbidden'));
    }
}
