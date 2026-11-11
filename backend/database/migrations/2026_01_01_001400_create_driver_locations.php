<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Breadcrumb trail of drivers on duty: powers the customer's live map and the dashboard fleet view.
        // Rows older than 7 days are pruned daily (drivers:prune-locations), so the table stays small.
        Schema::create('driver_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('driver_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('delivery_id')->nullable()->constrained('deliveries')->nullOnDelete();
            $table->decimal('lat', 9, 6);
            $table->decimal('lng', 9, 6);
            $table->decimal('speed_kmh', 6, 1)->nullable();
            $table->unsignedSmallInteger('heading')->nullable();
            $table->unsignedSmallInteger('accuracy_m')->nullable();
            $table->timestampTz('recorded_at');
            $table->index(['delivery_id', 'recorded_at']);
            $table->index(['driver_id', 'recorded_at']);
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_locations');
    }
};
