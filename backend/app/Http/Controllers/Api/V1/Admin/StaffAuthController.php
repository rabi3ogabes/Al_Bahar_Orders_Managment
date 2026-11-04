<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Enums\UserType;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Services\AuthService;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Auth\PasswordLoginRequest;
use App\Http\Resources\V1\UserResource;
use Illuminate\Http\JsonResponse;

/**
 * Staff API authentication (separate channel from customers). Tokens carry the "staff" ability.
 *
 * @tags Admin · Auth
 */
class StaffAuthController extends ApiController
{
    public function login(PasswordLoginRequest $request, AuthService $auth): JsonResponse
    {
        $result = $auth->passwordLogin($request->validated(), $this->context($request), UserType::Staff);

        return response()->json(['data' => [
            'status' => 'authenticated',
            'user' => new UserResource($result['user']),
            'tokens' => $result['tokens'],
        ]]);
    }

    /** Current staff member with effective permissions and branch scope. */
    public function me(AccessControl $access): JsonResponse
    {
        $user = $this->user()->load('branches');

        return response()->json(['data' => [
            'user' => new UserResource($user),
            'is_super_admin' => $user->isSuperAdmin(),
            'permissions' => $access->permissionKeys($user),
            'branches' => $user->branches->where('pivot.is_active', true)->map(fn ($b) => [
                'id' => $b->id, 'code' => $b->code, 'name' => $b->localized('name'),
            ])->values(),
        ]]);
    }
}
