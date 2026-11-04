<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserSession;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Short-lived Sanctum access tokens + long-lived rotating refresh tokens.
 * Refresh tokens are stored as SHA-256 hashes. Each refresh rotates the token; presenting an
 * already-rotated token is treated as theft and revokes the entire session family.
 */
class TokenService
{
    /** @return array{access_token:string, refresh_token:string, token_type:string, expires_in:int, session_id:string} */
    public function issue(User $user, ?string $deviceName = null, ?string $platform = null, ?string $ip = null, ?string $userAgent = null, ?string $familyId = null): array
    {
        $ttl = config('platform.auth.access_token_ttl_minutes');
        $abilities = $user->isStaff() ? ['staff'] : ['customer'];
        $access = $user->createToken($deviceName ?: 'api', $abilities, now()->addMinutes($ttl));
        $refresh = Str::random(80);

        $session = UserSession::create([
            'user_id' => $user->getKey(),
            'family_id' => $familyId ?? (string) Str::uuid(),
            'refresh_token_hash' => hash('sha256', $refresh),
            'access_token_id' => $access->accessToken->getKey(),
            'device_name' => $deviceName ? substr($deviceName, 0, 120) : null,
            'platform' => $platform,
            'ip' => $ip,
            'user_agent' => $userAgent ? substr($userAgent, 0, 512) : null,
            'expires_at' => now()->addDays(config('platform.auth.refresh_token_ttl_days')),
            'last_used_at' => now(),
        ]);

        $this->enforceSessionLimit($user);

        return [
            'access_token' => $access->plainTextToken,
            'refresh_token' => $refresh,
            'token_type' => 'Bearer',
            'expires_in' => $ttl * 60,
            'session_id' => $session->id,
        ];
    }

    public function refresh(string $refreshToken, ?string $ip = null, ?string $userAgent = null): array
    {
        // Revocations must persist even when the refresh is rejected, so failures are returned from the
        // transaction and thrown only after it commits.
        [$tokens, $error, $status] = DB::transaction(function () use ($refreshToken, $ip, $userAgent) {
            /** @var UserSession|null $session */
            $session = UserSession::where('refresh_token_hash', hash('sha256', $refreshToken))->lockForUpdate()->first();

            if (! $session) {
                return [null, 'refresh_invalid', 401];
            }
            if ($session->rotated_at !== null || $session->revoked_at !== null) {
                // Reuse of a rotated/revoked token: revoke the whole family (possible token theft).
                $this->revokeFamily($session->family_id, 'refresh_reuse_detected');

                return [null, 'refresh_invalid', 401];
            }
            if ($session->expires_at->isPast()) {
                $this->revoke($session, 'expired');

                return [null, 'refresh_expired', 401];
            }
            $user = $session->user;
            if (! $user || ! $user->isActive()) {
                $this->revoke($session, 'user_inactive');

                return [null, 'account_inactive', 403];
            }

            $session->update(['rotated_at' => now(), 'revoked_at' => now(), 'revoked_reason' => 'rotated']);
            PersonalAccessToken::whereKey($session->access_token_id)->delete();

            return [$this->issue($user, $session->device_name, $session->platform, $ip, $userAgent, $session->family_id), null, 200];
        });

        if ($error !== null) {
            throw DomainException::make($error, $status);
        }

        return $tokens;
    }

    public function revoke(UserSession $session, string $reason = 'logout'): void
    {
        $session->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
        PersonalAccessToken::whereKey($session->access_token_id)->delete();
    }

    public function revokeByAccessToken(PersonalAccessToken $token, string $reason = 'logout'): void
    {
        $session = UserSession::where('access_token_id', $token->getKey())->whereNull('revoked_at')->first();
        $session ? $this->revoke($session, $reason) : $token->delete();
    }

    public function revokeFamily(string $familyId, string $reason): void
    {
        UserSession::where('family_id', $familyId)->get()->each(function (UserSession $s) use ($reason) {
            PersonalAccessToken::whereKey($s->access_token_id)->delete();
            if ($s->revoked_at === null) {
                $s->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
            }
        });
    }

    public function revokeAll(User $user, string $reason = 'logout_all'): void
    {
        UserSession::where('user_id', $user->getKey())->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
        $user->tokens()->delete();
    }

    private function enforceSessionLimit(User $user): void
    {
        $max = config('platform.auth.max_sessions_per_user');
        $active = UserSession::where('user_id', $user->getKey())->whereNull('revoked_at')->orderByDesc('created_at')->get();
        $active->slice($max)->each(fn (UserSession $s) => $this->revoke($s, 'session_limit'));
    }
}
