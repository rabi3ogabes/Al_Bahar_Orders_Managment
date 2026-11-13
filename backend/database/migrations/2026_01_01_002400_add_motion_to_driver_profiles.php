<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The latest fix of a driver, as the phone reported it: how it was moving, how sure the GPS was and when it
        // was taken (last_seen_at is when the SERVER last heard from the app, which also changes on a duty switch).
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->unsignedSmallInteger('last_heading')->nullable()->after('last_lng');
            $table->decimal('last_speed_kmh', 6, 1)->nullable()->after('last_heading');
            $table->unsignedInteger('last_accuracy_m')->nullable()->after('last_speed_kmh');
            $table->unsignedSmallInteger('last_battery')->nullable()->after('last_accuracy_m');
            $table->timestampTz('last_fix_at')->nullable()->after('last_battery');
            // Shown to customers and staff next to the driver's first name, e.g. "Motorbike · 123456".
            $table->string('vehicle', 60)->nullable()->after('last_assigned_at');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn(['last_heading', 'last_speed_kmh', 'last_accuracy_m', 'last_battery', 'last_fix_at', 'vehicle']);
        });
    }
};
