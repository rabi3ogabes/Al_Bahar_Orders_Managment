<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserDevice;
use App\Domain\Identity\Services\AuthService;
use App\Domain\Identity\Services\DeviceRegistry;
use App\Domain\Identity\Services\OtpService;
use App\Domain\Identity\Services\TokenService;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Auth\PasswordLoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Requests\Api\V1\Auth\RequestOtpRequest;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use App\Http\Requests\Api\V1\Auth\VerifyOtpRequest;
use App\Http\Resources\V1\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Auth
 */
class AuthController extends ApiController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly OtpService $otp,
        private readonly TokenService $tokens,
    ) {}

    /** Request a one-time code by SMS (login/registration or password reset). */
    public function requestOtp(RequestOtpRequest $request): JsonResponse
    {
        $purpose = OtpPurpose::from($request->input('purpose', 'login'));
        $phone = $request->string('phone')->toString();

        // Password reset for an unknown number responds identically to avoid account enumeration.
        if ($purpose === OtpPurpose::PasswordReset && ! User::where('phone', $phone)->where('type', 'customer')->exists()) {
            return response()->json(['data' => ['sent' => true, 'expires_in' => config('platform.otp.ttl_seconds'), 'resend_in' => config('platform.otp.resend_cooldown_seconds')], 'message' => __('auth.otp_sent')]);
        }

        $result = $this->otp->issue($phone, $purpose, $request->ip(), $request->userAgent());

        return response()->json(['data' => array_filter([
            'sent' => true,
            'expires_in' => config('platform.otp.ttl_seconds'),
            'resend_in' => config('platform.otp.resend_cooldown_seconds'),
            'debug_code' => $result['debug_code'], // only in local/testing with OTP_DEV_ECHO
        ], fn ($v) => $v !== null), 'message' => __('auth.otp_sent')]);
    }

    /** Verify the login code. Returns tokens, or a registration token for a new number. */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $result = $this->auth->verifyLoginOtp($request->string('phone'), $request->string('code'), $this->context($request));

        if ($result['status'] === 'authenticated') {
            return response()->json(['data' => [
                'status' => 'authenticated',
                'user' => new UserResource($result['user']),
                'tokens' => $result['tokens'],
            ]]);
        }

        return response()->json(['data' => $result]);
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->auth->register($request->validated(), $this->context($request));

        return response()->json(['data' => [
            'status' => 'authenticated',
            'user' => new UserResource($result['user']),
            'tokens' => $result['tokens'],
        ]], 201);
    }

    public function login(PasswordLoginRequest $request): JsonResponse
    {
        $result = $this->auth->passwordLogin($request->validated(), $this->context($request));

        return response()->json(['data' => ['status' => 'authenticated', 'user' => new UserResource($result['user']), 'tokens' => $result['tokens']]]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->auth->resetPassword($request->string('phone'), $request->string('code'), $request->string('password'));

        return response()->json(['data' => ['reset' => true], 'message' => __('auth.password_reset_done')]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $request->validate(['refresh_token' => ['required', 'string', 'max:200']]);

        return response()->json(['data' => ['tokens' => $this->tokens->refresh($request->string('refresh_token'), $request->ip(), $request->userAgent())]]);
    }

    /** Signs out this session. A client may also send its push token so this phone or browser stops getting notifications at once. */
    public function logout(Request $request, DeviceRegistry $devices): JsonResponse
    {
        $request->validate(['push_token' => ['nullable', 'string', 'max:512']]);
        if (is_string($request->input('push_token')) && $request->input('push_token') !== '') {
            $devices->disable($request->user(), $request->input('push_token'));
        }
        $this->tokens->revokeByAccessToken($request->user()->currentAccessToken());

        return response()->json(['data' => ['logged_out' => true], 'message' => __('auth.logged_out')]);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        UserDevice::where('user_id', $request->user()->id)->whereNull('disabled_at')->update(['disabled_at' => now()]); // signed out everywhere → no more pushes anywhere
        $this->tokens->revokeAll($request->user());

        return response()->json(['data' => ['logged_out' => true], 'message' => __('auth.logged_out')]);
    }
}
