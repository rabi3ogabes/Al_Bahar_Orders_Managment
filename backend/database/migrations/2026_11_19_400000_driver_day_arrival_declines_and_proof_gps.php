<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live Operations Control, the driver's part (docs/guides/DRIVER_APP.md):
 *
 *  - deliveries.arrived_at     the driver tapped "I have arrived" (once; the customer is told once)
 *  - deliveries.nearby_at      the customer was told the driver is close (once per delivery)
 *  - delivery_proofs.latitude / longitude / accuracy_m   where the driver stood when he took the proof (or reported the failed attempt)
 *  - delivery_declines         a driver who handed a job back, or who did not answer in time: that driver is never offered the same job again
 *  - driver_profiles.on_duty_since   when the driver went on duty (the "My day" screen)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->timestampTz('arrived_at')->nullable();
            $table->timestampTz('nearby_at')->nullable();
        });

        Schema::table('delivery_proofs', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('accuracy_m')->nullable();
        });

        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->timestampTz('on_duty_since')->nullable();
        });

        Schema::create('delivery_declines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('driver_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 24); // too_far | vehicle | shift_ending | other | timeout | off_duty
            $table->string('note', 300)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['delivery_id', 'driver_id']);
            $table->index(['driver_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_declines');

        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn('on_duty_since');
        });

        Schema::table('delivery_proofs', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'accuracy_m']);
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn(['arrived_at', 'nearby_at']);
        });
    }
};
