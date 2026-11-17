<?php

namespace App\Domain\Identity\Models;

use App\Domain\Shared\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use HasFactory, HasTranslations;

    public const SUPER_ADMIN = 'super_admin';

    protected $guarded = ['id'];

    protected $casts = ['is_system' => 'boolean'];

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    public function isGlobal(): bool
    {
        return $this->scope === 'global';
    }

    /**
     * A personal role is the feature checklist of ONE employee at ONE branch (see BranchEmployeeService). It authorises exactly like any
     * branch role, but it is never listed, never picked for somebody else and is removed with its employee or branch.
     */
    public function isPersonal(): bool
    {
        return $this->personal_user_id !== null;
    }

    /**
     * Roles an admin can see, pick and assign: everything except personal roles.
     *
     * @param  Builder<Role>  $query
     */
    public function scopeShared(Builder $query): void
    {
        $query->whereNull('personal_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function personalUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'personal_user_id');
    }
}
