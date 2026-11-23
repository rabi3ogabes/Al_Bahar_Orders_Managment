<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partners as records: one revocable link and password each, limited to some branches, with an optional expiry.
 * The access log learns which partner opened what, and from which (hashed) address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('contact', 191)->nullable();
            $table->string('token', 64)->unique();
            $table->string('password_hash');
            $table->boolean('all_branches')->default(false);
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestampTz('locked_until')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index('created_at');
        });

        Schema::create('partner_branches', function (Blueprint $table) {
            $table->foreignUuid('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->primary(['partner_id', 'branch_id']);
            $table->index('branch_id');
        });

        Schema::table('partner_access_logs', function (Blueprint $table) {
            $table->foreignUuid('partner_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->string('ip_hash', 64)->nullable();
            $table->index(['partner_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('partner_access_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_id');
            $table->dropColumn('ip_hash');
        });
        Schema::dropIfExists('partner_branches');
        Schema::dropIfExists('partners');
    }
};
