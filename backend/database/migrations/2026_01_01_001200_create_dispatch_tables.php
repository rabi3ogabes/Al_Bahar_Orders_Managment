<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who hands a delivery to a driver: the system (auto) or a branch employee (manual). Admin chooses per branch.
        Schema::table('branches', function (Blueprint $table) {
            $table->string('dispatch_mode', 16)->default('manual');
        });
        DB::statement("ALTER TABLE branches ADD CONSTRAINT branches_dispatch_mode_check CHECK (dispatch_mode IN ('manual','auto'))");

        // One row per driver (a driver can work for several branches); duty status and last position are per person.
        Schema::create('driver_profiles', function (Blueprint $table) {
            $table->foreignUuid('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('on_duty')->default(false);
            $table->decimal('last_lat', 9, 6)->nullable();
            $table->decimal('last_lng', 9, 6)->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('last_assigned_at')->nullable();
            $table->timestampsTz();
            $table->index(['on_duty']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_profiles');
        DB::statement('ALTER TABLE branches DROP CONSTRAINT IF EXISTS branches_dispatch_mode_check');
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn('dispatch_mode'));
    }
};
