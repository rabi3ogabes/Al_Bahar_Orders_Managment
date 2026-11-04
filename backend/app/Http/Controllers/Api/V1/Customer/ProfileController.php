<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\AccountDeletionRequest;
use App\Domain\Identity\Models\UserDevice;
use App\Domain\Identity\Models\UserSession;
use App\Domain\Identity\Services\TokenService;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\V1\UserResource;
use App\Support\BusinessTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * @tags Account
 */
class ProfileController extends ApiController
{
    public function show(): UserResource
    {
        return new UserResource($this->user());
    }

    public function update(Request $request): UserResource
    {
        $user = $this->user();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:191', Rule::unique('users', 'email')->ignore($user->id)],
            'locale' => ['sometimes', 'in:en,ar'],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'gender' => ['sometimes', 'nullable', 'in:male,female'],
            'marketing_consent' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('marketing_consent', $data)) {
            $data['marketing_consent_at'] = $data['marketing_consent'] ? ($user->marketing_consent_at ?? now()) : null;
            unset($data['marketing_consent']);
        }
        $user->fill($data)->save();
        app(AuditLogger::class)->logChanges('account.updated', $user);

        return new UserResource($user->refresh());
    }

    public function changePassword(Request $request, TokenService $tokens): JsonResponse
    {
        $user = $this->user();
        $request->validate([
            'current_password' => [$user->password ? 'required' : 'nullable', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);
        if ($user->password && ! Hash::check($request->input('current_password'), $user->password)) {
            return response()->json(['message' => __('auth.password'), 'code' => 'invalid_password', 'errors' => ['current_password' => [__('auth.password')]]], 422);
        }
        $user->forceFill(['password' => $request->input('password')])->save();
        // Keep the current session, revoke all others.
        UserSession::where('user_id', $user->id)->whereNull('revoked_at')
            ->where('access_token_id', '!=', $user->currentAccessToken()->id)->get()
            ->each(fn ($s) => $tokens->revoke($s, 'password_changed'));
        app(AuditLogger::class)->log('account.password_changed', $user);

        return response()->json(['data' => ['updated' => true]]);
    }

    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', 'in:android,ios,web'],
            'push_token' => ['required', 'string', 'max:512'],
            'device_id' => ['nullable', 'string', 'max:191'],
            'app_version' => ['nullable', 'string', 'max:32'],
        ]);
        // A push token belongs to exactly one user/device: re-registering moves it.
        $device = UserDevice::updateOrCreate(['push_token' => $data['push_token']], [
            ...$data,
            'user_id' => $this->user()->id,
            'locale' => app()->getLocale(),
            'last_seen_at' => now(),
            'disabled_at' => null,
        ]);

        return response()->json(['data' => ['id' => $device->id, 'registered' => true]], 201);
    }

    public function unregisterDevice(Request $request): JsonResponse
    {
        $request->validate(['push_token' => ['required', 'string', 'max:512']]);
        UserDevice::where('user_id', $this->user()->id)->where('push_token', $request->input('push_token'))
            ->update(['disabled_at' => now()]);

        return response()->json(['data' => ['unregistered' => true]]);
    }

    public function sessions(): JsonResponse
    {
        $currentId = $this->user()->currentAccessToken()->id;
        $sessions = UserSession::where('user_id', $this->user()->id)->whereNull('revoked_at')
            ->where('expires_at', '>', now())->orderByDesc('last_used_at')->get()
            ->map(fn (UserSession $s) => [
                'id' => $s->id,
                'device_name' => $s->device_name,
                'platform' => $s->platform,
                'ip' => $s->ip,
                'last_used_at' => BusinessTime::iso($s->last_used_at),
                'created_at' => BusinessTime::iso($s->created_at),
                'is_current' => (int) $s->access_token_id === (int) $currentId,
            ]);

        return response()->json(['data' => $sessions]);
    }

    public function revokeSession(string $id, TokenService $tokens): JsonResponse
    {
        $session = UserSession::where('user_id', $this->user()->id)->findOrFail($id);
        $tokens->revoke($session, 'revoked_by_user');

        return response()->json(['data' => ['revoked' => true]]);
    }

    /** Privacy: request account deletion. Personal data is anonymised; order/financial history is retained as required by law. */
    public function requestDeletion(Request $request): JsonResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $user = $this->user();
        $existing = AccountDeletionRequest::where('user_id', $user->id)->whereIn('status', ['pending', 'processing'])->first();
        $deletion = $existing ?? AccountDeletionRequest::create([
            'user_id' => $user->id,
            'reason' => $request->input('reason'),
            'scheduled_for' => now()->addDays(14),
        ]);
        app(AuditLogger::class)->log('account.deletion_requested', $user);

        return response()->json(['data' => [
            'id' => $deletion->id,
            'status' => $deletion->status,
            'scheduled_for' => BusinessTime::iso($deletion->scheduled_for),
        ]], $existing ? 200 : 201);
    }

    public function cancelDeletion(): JsonResponse
    {
        AccountDeletionRequest::where('user_id', $this->user()->id)->where('status', 'pending')->update(['status' => 'cancelled']);

        return response()->json(['data' => ['cancelled' => true]]);
    }
}
