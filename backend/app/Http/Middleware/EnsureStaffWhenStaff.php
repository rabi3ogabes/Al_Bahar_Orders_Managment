<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For endpoints that customers and staff both use (the broadcasting auth route of the apps): a staff member passes through the full staff
 * policy (active account, current browser session, first steps such as choosing their own password), a customer is left alone.
 */
class EnsureStaffWhenStaff
{
    public function __construct(private readonly EnsureStaff $staff) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isStaff()) {
            return $this->staff->handle($request, $next);
        }

        return $next($request);
    }
}
