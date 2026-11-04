<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 02 — Company, branches, employees and operating configuration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 24)->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->text('description_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('address_en')->nullable();
            $table->string('address_ar')->nullable();
            $table->string('area')->nullable();           // zone / area label
            $table->string('zone_number', 16)->nullable(); // Qatar address zone
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('timezone', 48)->default('Asia/Qatar');
            $table->string('opening_status', 24)->default('open'); // open | temporarily_closed
            $table->boolean('pickup_enabled')->default(true);
            $table->boolean('delivery_enabled')->default(true);
            $table->boolean('cod_enabled')->default(true);
            $table->boolean('pay_at_pickup_enabled')->default(true);
            $table->jsonb('pay_at_pickup_tenders')->nullable(); // ["cash","card_terminal"]
            $table->decimal('cod_min_order', 12, 2)->nullable();
            $table->decimal('cod_max_order', 12, 2)->nullable();
            $table->unsignedSmallInteger('pickup_preparation_minutes')->default(60);
            $table->boolean('is_fulfillment_hub')->default(false);
            $table->string('status', 16)->default('active'); // active | inactive
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->index(['status', 'sort_order']);
        });
        DB::statement("ALTER TABLE branches ADD CONSTRAINT branches_status_check CHECK (status IN ('active','inactive'))");
        DB::statement("ALTER TABLE branches ADD CONSTRAINT branches_opening_status_check CHECK (opening_status IN ('open','temporarily_closed'))");
        DB::statement('ALTER TABLE branches ADD CONSTRAINT branches_lat_check CHECK (latitude IS NULL OR (latitude BETWEEN -90 AND 90))');
        DB::statement('ALTER TABLE branches ADD CONSTRAINT branches_lng_check CHECK (longitude IS NULL OR (longitude BETWEEN -180 AND 180))');

        Schema::table('user_roles', function (Blueprint $table) {
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
        });

        Schema::create('branch_employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('job_title')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_delivery_driver')->default(false);
            $table->foreignUuid('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['branch_id', 'user_id']);
            $table->index(['user_id', 'is_active']);
        });

        Schema::create('branch_hours', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // 0 = Sunday ... 6 = Saturday (Carbon convention)
            $table->boolean('is_closed')->default(false);
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable(); // may be <= opens_at for overnight hours
            $table->time('break_starts_at')->nullable();
            $table->time('break_ends_at')->nullable();
            $table->timestampsTz();

            $table->unique(['branch_id', 'day_of_week']);
        });
        DB::statement('ALTER TABLE branch_hours ADD CONSTRAINT branch_hours_dow_check CHECK (day_of_week BETWEEN 0 AND 6)');
        DB::statement('ALTER TABLE branch_hours ADD CONSTRAINT branch_hours_open_check CHECK (is_closed OR (opens_at IS NOT NULL AND closes_at IS NOT NULL))');

        Schema::create('branch_holidays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24)->default('holiday'); // holiday | temporary_closure
            $table->string('name_en');
            $table->string('name_ar');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->text('reason')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['branch_id', 'starts_at', 'ends_at']);
        });
        DB::statement('ALTER TABLE branch_holidays ADD CONSTRAINT branch_holidays_range_check CHECK (ends_at > starts_at)');

        Schema::create('branch_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('branch_id')->constrained()->cascadeOnDelete();
            $table->string('key', 96);
            $table->jsonb('value')->nullable();
            $table->timestampsTz();

            $table->unique(['branch_id', 'key']);
        });

        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 32)->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('zone_numbers')->nullable(); // comma-separated Qatar zone numbers covered
            $table->jsonb('polygon')->nullable();        // optional GeoJSON polygon [[lng,lat],...]
            $table->boolean('is_remote')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('branch_delivery_zones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('delivery_zone_id')->constrained()->cascadeOnDelete();
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->decimal('free_delivery_threshold', 12, 2)->nullable();
            $table->decimal('min_order_amount', 12, 2)->nullable();
            $table->decimal('max_order_amount', 12, 2)->nullable();
            $table->decimal('oversized_surcharge', 12, 2)->default(0);
            $table->boolean('cod_enabled')->default(true);
            $table->boolean('express_enabled')->default(false);
            $table->decimal('express_fee', 12, 2)->nullable();
            $table->unsignedInteger('estimated_minutes')->nullable();
            $table->unsignedSmallInteger('priority')->default(100); // lower = preferred
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['branch_id', 'delivery_zone_id']);
        });
        DB::statement('ALTER TABLE branch_delivery_zones ADD CONSTRAINT bdz_money_check CHECK (delivery_fee >= 0 AND oversized_surcharge >= 0 AND (min_order_amount IS NULL OR max_order_amount IS NULL OR max_order_amount >= min_order_amount))');

        Schema::create('delivery_slots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->cascadeOnDelete();
            $table->string('fulfillment_type', 16); // delivery | pickup
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->unsignedSmallInteger('capacity');
            $table->unsignedSmallInteger('cutoff_minutes')->default(60);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['branch_id', 'fulfillment_type', 'day_of_week']);
        });
        DB::statement("ALTER TABLE delivery_slots ADD CONSTRAINT delivery_slots_type_check CHECK (fulfillment_type IN ('delivery','pickup'))");
        DB::statement('ALTER TABLE delivery_slots ADD CONSTRAINT delivery_slots_range_check CHECK (ends_at > starts_at AND capacity > 0)');

        // Per-date booking counter used to enforce slot capacity under row lock.
        Schema::create('delivery_slot_bookings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('delivery_slot_id')->constrained()->cascadeOnDelete();
            $table->date('slot_date');
            $table->unsignedSmallInteger('booked')->default(0);
            $table->timestampsTz();

            $table->unique(['delivery_slot_id', 'slot_date']);
        });
        DB::statement('ALTER TABLE delivery_slot_bookings ADD CONSTRAINT dsb_booked_check CHECK (booked >= 0)');
    }

    public function down(): void
    {
        foreach (['delivery_slot_bookings', 'delivery_slots', 'branch_delivery_zones', 'delivery_zones', 'branch_settings',
            'branch_holidays', 'branch_hours', 'branch_employees'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('user_roles', fn (Blueprint $t) => $t->dropForeign(['branch_id']));
        Schema::dropIfExists('branches');
    }
};
