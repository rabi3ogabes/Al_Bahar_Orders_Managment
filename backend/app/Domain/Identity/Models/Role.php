<?php

namespace App\Domain\Identity\Models;

use App\Domain\Shared\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use HasFactory, HasTranslations;

    public const SUPER_ADMIN = 'super_admin';

    protected $guarded = ['id'];

    protected $casts = ['is_system' => 'boolean'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    public function isGlobal(): bool
    {
        return $this->scope === 'global';
    }
}
