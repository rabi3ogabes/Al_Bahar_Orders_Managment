<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Support\BranchScope;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

abstract class ApiController extends Controller
{
    protected function user(): User
    {
        /** @var User */
        return request()->user();
    }

    protected function context(Request $request): array
    {
        return [
            'device_name' => $request->input('device_name'),
            'platform' => $request->input('platform'),
            'device_id' => $request->input('device_id'),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];
    }

    /** Server-derived branch scope, optionally narrowed (never widened) by ?branch_id. */
    protected function scope(string $permission, ?string $requestedBranch = null): BranchScope
    {
        return app(AccessControl::class)->scope($this->user(), $permission)->narrow($requestedBranch);
    }

    protected function authorizeBranch(string $permission, ?string $branchId): void
    {
        app(AccessControl::class)->authorize($this->user(), $permission, $branchId);
    }

    protected function perPage(Request $request, int $default = 20): int
    {
        return max(1, min(100, (int) $request->integer('per_page', $default)));
    }
}
