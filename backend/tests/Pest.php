<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserRole;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

pest()->extend(Tests\TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Unit');

/** Create an active staff member holding $roleKey, scoped to $branch (or company-wide when null). */
function staffWithRole(string $roleKey, ?Branch $branch = null, array $attributes = []): User
{
    if (! Role::where('key', $roleKey)->exists()) {
        test()->seed(RolesAndPermissionsSeeder::class);
    }
    $user = User::factory()->staff()->create($attributes);
    $role = Role::where('key', $roleKey)->firstOrFail();
    if ($branch) {
        BranchEmployee::create(['branch_id' => $branch->id, 'user_id' => $user->id, 'is_active' => true]);
    }
    UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'branch_id' => $branch?->id]);

    return $user;
}

function actingAsStaff(User $user): User
{
    Sanctum::actingAs($user, ['staff']);

    return $user;
}

function actingAsCustomer(?User $user = null): User
{
    $user ??= User::factory()->create();
    Sanctum::actingAs($user, ['customer']);

    return $user;
}
