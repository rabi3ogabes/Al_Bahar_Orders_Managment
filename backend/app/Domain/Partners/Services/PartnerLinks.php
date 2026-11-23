<?php

namespace App\Domain\Partners\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Partners\Models\Partner;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Per-partner links: create, regenerate, revoke, expire, and check a password with a lockout. A password is shown ONCE (when it is made)
 * and stored only as a hash. Every change is audit-logged; every partner visit is written to partner_access_logs with the partner and a
 * hash of the address (never the address itself).
 */
class PartnerLinks
{
    public const MAX_FAILED = 5;

    public const LOCK_MINUTES = 15;

    public function __construct(private readonly AuditLogger $audit) {}

    public function findByToken(string $token): ?Partner
    {
        return strlen($token) >= 20 ? Partner::where('token', $token)->first() : null;
    }

    public function url(Partner $p): string
    {
        return url('/partner/'.$p->token);
    }

    /**
     * @param  list<string>  $branchIds
     * @return array{partner: Partner, password: string}
     */
    public function create(string $name, ?string $contact, array $branchIds, bool $allBranches, ?CarbonInterface $expiresAt, ?User $by): array
    {
        $password = $this->newPassword();
        $partner = DB::transaction(function () use ($name, $contact, $branchIds, $allBranches, $expiresAt, $by, $password) {
            $p = Partner::create(['name' => $name, 'contact' => $contact, 'token' => Str::random(40), 'password_hash' => Hash::make($password),
                'all_branches' => $allBranches, 'expires_at' => $expiresAt, 'created_by' => $by?->id]);
            $p->branches()->sync($allBranches ? [] : $branchIds);

            return $p;
        });
        $this->audit->log('partner.created', $partner, [], ['name' => $name, 'all_branches' => $allBranches, 'branches' => $branchIds, 'expires_at' => $expiresAt?->toIso8601String()], actor: $by);

        return ['partner' => $partner, 'password' => $password];
    }

    /** @param list<string> $branchIds */
    public function update(Partner $p, string $name, ?string $contact, array $branchIds, bool $allBranches, ?CarbonInterface $expiresAt, ?User $by): void
    {
        $old = ['name' => $p->name, 'all_branches' => $p->all_branches, 'branches' => $p->branches()->pluck('branches.id')->sort()->values()->all(), 'expires_at' => $p->expires_at?->toIso8601String()];
        DB::transaction(function () use ($p, $name, $contact, $branchIds, $allBranches, $expiresAt) {
            $p->update(['name' => $name, 'contact' => $contact, 'all_branches' => $allBranches, 'expires_at' => $expiresAt]);
            $p->branches()->sync($allBranches ? [] : $branchIds);
        });
        $this->audit->log('partner.updated', $p, $old, ['name' => $name, 'all_branches' => $allBranches, 'branches' => collect($branchIds)->sort()->values()->all(), 'expires_at' => $expiresAt?->toIso8601String()], actor: $by);
    }

    /** A new link AND a new password; the old ones stop working at once, the partner is signed out, and a revoked partner is open again. */
    public function regenerate(Partner $p, ?User $by): string
    {
        $password = $this->newPassword();
        $p->update(['token' => Str::random(40), 'password_hash' => Hash::make($password), 'revoked_at' => null, 'failed_attempts' => 0, 'locked_until' => null]);
        $this->audit->log('partner.regenerated', $p, [], [], actor: $by);

        return $password;
    }

    public function revoke(Partner $p, ?User $by): void
    {
        if ($p->revoked_at === null) {
            $p->update(['revoked_at' => now()]);
            $this->audit->log('partner.revoked', $p, [], [], actor: $by);
        }
    }

    /** Wrong passwords per LINK, whoever types them: five in a row lock the link for 15 minutes. */
    public function attempt(Partner $p, string $password): string
    {
        if ($p->locked_until !== null && $p->locked_until->isFuture()) {
            return 'locked';
        }
        if (Hash::check($password, $p->password_hash)) {
            $p->update(['failed_attempts' => 0, 'locked_until' => null, 'last_seen_at' => now()]);

            return 'ok';
        }
        $failed = $p->failed_attempts + 1;
        if ($failed >= self::MAX_FAILED) {
            $p->update(['failed_attempts' => 0, 'locked_until' => now()->addMinutes(self::LOCK_MINUTES)]);

            return 'locked';
        }
        $p->update(['failed_attempts' => $failed]);

        return 'wrong';
    }

    /** What this partner may see; null branches = every branch. */
    public function scope(Partner $p): PartnerScope
    {
        return new PartnerScope($p->all_branches ? null : $p->branches()->pluck('branches.id')->sort()->values()->all(), $p->id);
    }

    /** A signed-in browser stays valid only while the link and the password are the ones it unlocked. */
    public function fingerprint(Partner $p): string
    {
        return hash('sha256', $p->id.'|'.$p->token.'|'.$p->password_hash);
    }

    public function ipHash(?string $ip): ?string
    {
        return $ip === null ? null : hash_hmac('sha256', $ip, (string) config('app.key'));
    }

    /** @param bool $throttled  only one row per partner and address in 15 minutes (the live map polls every few seconds) */
    public function log(Partner $p, string $event, ?string $ip, ?string $agent, bool $throttled = false): void
    {
        $hash = $this->ipHash($ip);
        if ($throttled && ! Cache::add("partner-log:{$p->id}:{$event}:{$hash}", 1, 900)) {
            return;
        }
        DB::table('partner_access_logs')->insert(['partner_id' => $p->id, 'event' => $event, 'ip_hash' => $hash,
            'user_agent' => $agent ? Str::limit($agent, 250, '') : null, 'created_at' => now()]);
    }

    private function newPassword(): string
    {
        return Str::random(4).'-'.Str::random(4).'-'.Str::random(4).'-'.Str::random(4);
    }
}
