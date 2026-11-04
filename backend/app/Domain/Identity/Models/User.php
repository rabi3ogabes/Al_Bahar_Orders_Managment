<?php

namespace App\Domain\Identity\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Enums\UserType;
use App\Domain\Orders\Models\Order;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids, Notifiable, SoftDeletes;

    protected $guarded = ['id', 'is_super_admin', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    /** Mirrors DB defaults so freshly created models are complete under strict mode. */
    protected $attributes = [
        'type' => 'customer',
        'status' => 'active',
        'locale' => 'en',
        'is_super_admin' => false,
    ];

    protected function casts(): array
    {
        return [
            'type' => UserType::class,
            'status' => UserStatus::class,
            'is_super_admin' => 'boolean',
            'phone_verified_at' => 'immutable_datetime',
            'email_verified_at' => 'immutable_datetime',
            'marketing_consent_at' => 'immutable_datetime',
            'two_factor_confirmed_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'date_of_birth' => 'date',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
        ];
    }

    public function isStaff(): bool
    {
        return $this->type === UserType::Staff;
    }

    public function isCustomer(): bool
    {
        return $this->type === UserType::Customer;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin && $this->isStaff();
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')->withPivot(['id', 'branch_id'])->withTimestamps();
    }

    public function branchAssignments(): HasMany
    {
        return $this->hasMany(BranchEmployee::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_employees')->withPivot(['job_title', 'is_active', 'is_delivery_driver'])->withTimestamps();
    }

    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    public function sessionsList(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(\App\Domain\Orders\Models\UserAddress::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function loyaltyAccount(): HasOne
    {
        return $this->hasOne(\App\Domain\Marketing\Models\LoyaltyAccount::class);
    }

    public function preferredLocale(): string
    {
        return $this->locale ?: config('app.locale');
    }
}
