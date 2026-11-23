<?php

namespace App\Domain\Partners\Models;

use App\Domain\Branches\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One partner: a name, a contact, the branches they may see, one secret link and password (stored hashed), an optional expiry.
 *
 * @property string $id
 * @property string $name
 * @property string|null $contact
 * @property string $token
 * @property string $password_hash
 * @property bool $all_branches
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property int $failed_attempts
 * @property CarbonImmutable|null $locked_until
 * @property CarbonImmutable|null $last_seen_at
 */
class Partner extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['token', 'password_hash'];

    protected $casts = ['all_branches' => 'boolean', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime',
        'locked_until' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime'];

    /** @return BelongsToMany<Branch, $this> */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'partner_branches');
    }

    /** @return 'revoked'|'expired'|'locked'|'active' */
    public function status(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->expires_at !== null && $this->expires_at->isPast() => 'expired',
            $this->locked_until !== null && $this->locked_until->isFuture() => 'locked',
            default => 'active',
        };
    }

    /** A revoked or expired link is closed for good (a lock only waits). */
    public function isOpen(): bool
    {
        return ! in_array($this->status(), ['revoked', 'expired'], true);
    }
}
