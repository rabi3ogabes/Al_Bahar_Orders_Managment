<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(ReferenceDataSeeder::class);

        // Demo data is NEVER seeded in production.
        if (app()->environment(['local', 'development', 'staging', 'testing'])) {
            $this->call(DemoSeeder::class);
        }
    }
}
