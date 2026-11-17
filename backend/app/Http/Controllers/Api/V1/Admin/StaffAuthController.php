<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Enums\UserType;
use App\Domain\Identity\Models\UserSession;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Services\AuthService;
use App\Domain\Identity\Services\TokenService;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Auth\PasswordLoginRequest;
use App\Http\Resources\V1\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

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
            // An administrator set this password: the app must send the person to "choose your own password" (PUT /admin/me/password).
            'must_change_password' => $result['user']->must_change_password,
        ]]);
    }

    /** Current staff member with effective permissions and branch scope. */
    public function me(AccessControl $access): JsonResponse
    {
        $user = $this->user()->load('branches');

        return response()->json(['data' => [
            'user' => new UserResource($user),
            'is_super_admin' => $user->isSuperAdmin(),
            'must_change_password' => $user->must_change_password,
            'permissions' => $access->permissionKeys($user),
            'branches' => $user->branches->where('pivot.is_active', true)->map(fn ($b) => [
                'id' => $b->id, 'code' => $b->code, 'name' => $b->localized('name'),
            ])->values(),
        ]]);
    }

    /**
     * The staff member chooses their own password (the first sign-in after an administrator created the account or reset the password,
     * or any time). Other devices are signed out; this one stays. Until it is done every other staff endpoint answers 403 password_change_required.
     */
    public function changePassword(Request $request, TokenService $tokens, AuditLogger $audit): JsonResponse
    {
        $user = $this->user();
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:200'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(12)->mixedCase()->numbers()],
        ]);
        if (! $user->password || ! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => __('auth.password'), 'code' => 'invalid_password', 'errors' => ['current_password' => [__('auth.password')]]], 422);
        }
        $user->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();
        UserSession::where('user_id', $user->id)->whereNull('revoked_at')
            ->where('access_token_id', '!=', $user->currentAccessToken()->id ?? 0)->get()
            ->each(fn (UserSession $s) => $tokens->revoke($s, 'password_changed'));
        $audit->log('staff.password_changed', $user);

        return response()->json(['data' => ['updated' => true, 'must_change_password' => false]]);
    }
}
