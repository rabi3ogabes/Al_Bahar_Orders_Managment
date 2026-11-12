<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Partner statistics access log, and the issue log (payment problems and bugs across the system and every app). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_access_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event', 24); // unlocked | failed | viewed_order
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index('created_at');
        });

        Schema::create('issue_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 16)->unique(); // ISS-000123
            $table->string('kind', 16);                // payment | bug | delivery | other
            $table->string('app', 16);                 // customer | driver | staff | dashboard | storefront | server
            $table->string('severity', 12)->default('medium');
            $table->string('status', 16)->default('open');
            $table->string('title', 191);
            $table->text('details')->nullable();
            $table->foreignUuid('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'severity', 'created_at']);
            $table->index(['kind', 'app']);
        });
        DB::statement("ALTER TABLE issue_reports ADD CONSTRAINT issue_reports_enums CHECK (kind IN ('payment','bug','delivery','other') AND app IN ('customer','driver','staff','dashboard','storefront','server') AND severity IN ('low','medium','high','critical') AND status IN ('open','investigating','resolved','wont_fix'))");
        DB::statement('CREATE SEQUENCE IF NOT EXISTS issue_reports_ref_seq START 1');
    }

    public function down(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS issue_reports_ref_seq');
        Schema::dropIfExists('issue_reports');
        Schema::dropIfExists('partner_access_logs');
    }
};
