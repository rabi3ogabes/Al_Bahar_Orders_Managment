<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer journeys: timed follow-ups (abandoned cart, birthday) sent by push / in-app / WhatsApp (through n8n),
 * built from reusable AR/EN message templates, optionally with a personal one-time coupon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journey_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('kind', 12)->default('reminder'); // reminder | offer
            $table->string('title_en', 120);
            $table->string('title_ar', 120);
            $table->text('body_en');
            $table->text('body_ar');
            $table->text('whatsapp_en')->nullable(); // longer WhatsApp wording; falls back to the push body
            $table->text('whatsapp_ar')->nullable();
            $table->string('deep_link', 191)->nullable();
            $table->foreignUuid('promotion_id')->nullable()->constrained()->nullOnDelete(); // personal coupons are issued under it
            $table->string('coupon_prefix', 8)->default('BAHAR');
            $table->unsignedSmallInteger('coupon_valid_hours')->default(72);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('journeys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 24)->unique(); // abandoned_cart | birthday
            $table->string('name', 120);
            $table->boolean('is_enabled')->default(false);
            $table->jsonb('settings')->nullable(); // quiet_start, quiet_end, max_per_day, stop_on_order
            $table->timestampsTz();
        });

        Schema::create('journey_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('journey_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(1);
            $table->string('label', 80)->nullable();
            $table->unsignedInteger('delay_minutes')->nullable(); // abandoned cart: minutes after the last cart activity
            $table->smallInteger('offset_days')->nullable();      // birthday: days from the birthday (-3 = three days before)
            $table->string('send_time', 5)->nullable();           // birthday: HH:MM in Qatar time
            $table->jsonb('channels');                            // ["push","whatsapp","in_app"]
            $table->foreignUuid('template_id')->nullable()->constrained('journey_templates')->nullOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->timestampsTz();

            $table->index(['journey_id', 'position']);
        });

        Schema::create('journey_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('journey_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('cart_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('anchor_at');            // cart last activity, or the birthday at 00:00 Qatar time
            $table->string('status', 12)->default('active'); // active | completed | converted | stopped
            $table->string('stop_reason', 32)->nullable();
            $table->foreignUuid('order_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('revenue', 12, 2)->default(0);
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->unique(['journey_id', 'user_id', 'anchor_at']);
            $table->index(['journey_id', 'status', 'anchor_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('journey_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('run_id')->constrained('journey_runs')->cascadeOnDelete();
            $table->foreignUuid('step_id')->nullable()->constrained('journey_steps')->nullOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 12);               // push | in_app | whatsapp
            $table->string('status', 12)->default('queued'); // queued | sent | delivered | failed | skipped
            $table->string('reason', 48)->nullable();    // why skipped / failed
            $table->string('locale', 5)->nullable();
            $table->string('coupon_code', 48)->nullable();
            $table->string('external_id', 96)->nullable(); // n8n / WhatsApp message id
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();

            $table->unique(['run_id', 'step_id', 'channel']);
            $table->index(['user_id', 'sent_at']);
            $table->index('external_id');
        });
        DB::statement("ALTER TABLE journey_deliveries ADD CONSTRAINT journey_deliveries_channel_check CHECK (channel IN ('push','in_app','whatsapp'))");
    }

    public function down(): void
    {
        foreach (['journey_deliveries', 'journey_runs', 'journey_steps', 'journeys', 'journey_templates'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
