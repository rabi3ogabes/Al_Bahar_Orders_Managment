<?php

namespace App\Domain\Partners\Services;

use App\Domain\Settings\Services\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The partners' secret link + password. The link carries a random token; the password is stored only as a hash.
 * Rotating the link or changing the password signs every partner out (the session fingerprint changes).
 */
class PartnerAccess
{
    public function __construct(private readonly Settings $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('partners.share_enabled', false) && $this->token() !== null && $this->hash() !== null;
    }

    public function token(): ?string
    {
        return $this->settings->get('partners.share_token') ?: null;
    }

    private function hash(): ?string
    {
        return $this->settings->get('partners.share_password_hash') ?: null;
    }

    public function hasPassword(): bool
    {
        return $this->hash() !== null;
    }

    public function url(): ?string
    {
        return $this->token() ? url('/partner/'.$this->token()) : null;
    }

    public function tokenMatches(string $given): bool
    {
        $token = $this->token();

        return $this->enabled() && $token !== null && hash_equals($token, $given);
    }

    public function passwordMatches(string $password): bool
    {
        $hash = $this->hash();

        return $hash !== null && Hash::check($password, $hash);
    }

    /** Changes with the link and the password: a stored session fingerprint stops matching after either changes. */
    public function fingerprint(): string
    {
        return hash('sha256', ($this->token() ?? '').'|'.($this->hash() ?? ''));
    }

    public function rotateLink(): string
    {
        $token = Str::random(40);
        $this->settings->set('partners.share_token', $token);

        return $token;
    }

    public function setPassword(string $password): void
    {
        $this->settings->set('partners.share_password_hash', Hash::make($password), secret: true);
    }

    public function setEnabled(bool $on): void
    {
        if ($on && $this->token() === null) {
            $this->rotateLink();
        }
        $this->settings->set('partners.share_enabled', $on);
    }

    public function log(string $event, ?string $ip, ?string $agent): void
    {
        DB::table('partner_access_logs')->insert(['event' => $event, 'ip' => $ip, 'user_agent' => $agent ? Str::limit($agent, 250, '') : null, 'created_at' => now()]);
    }

    /** @return list<object> */
    public function recent(int $n = 12): array
    {
        return DB::table('partner_access_logs')->orderByDesc('id')->limit($n)->get()->all();
    }
}
