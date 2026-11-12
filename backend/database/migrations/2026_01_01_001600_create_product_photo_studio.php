<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Photo studio: staff ask for a cleaner background or new designs of a product photo; the edited pictures wait for approval here. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_photo_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('source_image_id')->nullable()->constrained('product_images')->nullOnDelete();
            $table->string('kind', 24);   // clean_background | studio_white | lifestyle | new_design
            $table->unsignedSmallInteger('count')->default(1);
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('requested'); // requested | processing | ready | failed | cancelled
            $table->text('result_note')->nullable();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'created_at']);
        });
        DB::statement("ALTER TABLE product_photo_requests ADD CONSTRAINT photo_requests_enums CHECK (kind IN ('clean_background','studio_white','lifestyle','new_design') AND status IN ('requested','processing','ready','failed','cancelled') AND count BETWEEN 1 AND 6)");

        Schema::create('product_photo_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->constrained('product_photo_requests')->cascadeOnDelete();
            $table->string('disk', 32);
            $table->string('path');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('decision', 12)->default('pending'); // pending | used | discarded
            $table->foreignUuid('product_image_id')->nullable()->constrained('product_images')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['request_id', 'decision']);
        });
        DB::statement("ALTER TABLE product_photo_results ADD CONSTRAINT photo_results_decision CHECK (decision IN ('pending','used','discarded'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('product_photo_results');
        Schema::dropIfExists('product_photo_requests');
    }
};
