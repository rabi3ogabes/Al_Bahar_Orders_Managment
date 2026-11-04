<?php

namespace Database\Seeders;

use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Support\PermissionRegistry;
use Illuminate\Database\Seeder;

/**
 * Production-safe & idempotent: creates missing permissions and system roles, and grants each system
 * role its default permissions. It never revokes grants that administrators added later.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionRegistry::permissions() as $p) {
            Permission::updateOrCreate(['key' => $p['key']], $p);
        }
        $ids = Permission::pluck('id', 'key');

        foreach (PermissionRegistry::ROLES as $key => [$scope, $en, $ar, $patterns]) {
            $role = Role::updateOrCreate(['key' => $key], [
                'name_en' => $en, 'name_ar' => $ar, 'scope' => $scope, 'is_system' => true,
            ]);
            $grant = collect(PermissionRegistry::expand($patterns))->map(fn ($k) => $ids[$k])->all();
            $role->permissions()->syncWithoutDetaching($grant);
        }
    }
}
