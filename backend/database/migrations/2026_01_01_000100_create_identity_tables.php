<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 01 — Identity & access foundation.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 16)->default('customer'); // customer | staff
            $table->string('name');
            $table->string('phone', 20)->nullable()->unique();      // E.164, e.g. +97455123456
            $table->string('email')->nullable()->unique();
            $table->timestampTz('phone_verified_at')->nullable();
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->string('locale', 5)->default('en');
            $table->string('status', 16)->default('active'); // active | suspended | deactivated | deleted
            $table->boolean('is_super_admin')->default(false);
            $table->string('job_title')->nullable();
            $table->string('avatar_path')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 16)->nullable();
            $table->string('referral_code', 16)->nullable()->unique();
            $table->timestampTz('marketing_consent_at')->nullable();
            $table->text('two_factor_secret')->nullable();          // encrypted
            $table->text('two_factor_recovery_codes')->nullable();  // encrypted JSON of hashes
            $table->timestampTz('two_factor_confirmed_at')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->rememberToken();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['type', 'status']);
        });
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_type_check CHECK (type IN ('customer','staff'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active','suspended','deactivated','deleted'))");
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_contact_check CHECK (phone IS NOT NULL OR email IS NOT NULL)');

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('otp_verifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('phone', 20);
            $table->string('purpose', 24); // login | register | password_reset | phone_change | auction_verify
            $table->string('code_hash', 128);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts');
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('invalidated_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestampsTz();

            $table->index(['phone', 'purpose', 'created_at']);
        });

        Schema::create('user_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('family_id')->index(); // rotation family; reuse of a rotated token revokes the family
            $table->string('refresh_token_hash', 128)->unique();
            $table->unsignedBigInteger('access_token_id')->nullable();
            $table->string('device_name', 120)->nullable();
            $table->string('platform', 16)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('rotated_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoked_reason', 64)->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('user_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 16); // android | ios | web
            $table->string('push_token', 512);
            $table->string('device_id', 191)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->string('locale', 5)->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('disabled_at')->nullable();
            $table->timestampsTz();

            $table->unique('push_token');
            $table->index(['user_id', 'disabled_at']);
        });
        DB::statement("ALTER TABLE user_devices ADD CONSTRAINT user_devices_platform_check CHECK (platform IN ('android','ios','web'))");

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->text('description')->nullable();
            $table->string('scope', 16)->default('branch'); // global | branch
            $table->boolean('is_system')->default(false);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_scope_check CHECK (scope IN ('global','branch'))");

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 96)->unique(); // e.g. orders.refund
            $table->string('module', 48);
            $table->string('action', 24);        // view|create|edit|delete|approve|export|assign|refund|override
            $table->string('name_en');
            $table->string('name_ar');
            $table->timestampsTz();

            $table->index('module');
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->uuid('branch_id')->nullable(); // FK added in branches migration; NULL = company-wide (global roles only)
            $table->foreignUuid('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['user_id', 'branch_id']);
        });
        DB::statement('CREATE UNIQUE INDEX user_roles_unique_branch ON user_roles (user_id, role_id, branch_id) WHERE branch_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX user_roles_unique_global ON user_roles (user_id, role_id) WHERE branch_id IS NULL');

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('actor_id')->nullable()->index();
            $table->string('actor_type', 16)->nullable(); // staff | customer | system
            $table->string('action', 96);
            $table->string('auditable_type', 96)->nullable();
            $table->string('auditable_id', 64)->nullable();
            $table->uuid('branch_id')->nullable()->index();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['action', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('group', 48);
            $table->string('key', 96);
            $table->jsonb('value')->nullable();
            $table->boolean('is_secret')->default(false); // secret values are encrypted & masked in UI
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['group', 'key']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->string('scope', 48);        // checkout | payment_init | refund | bid ...
            $table->string('key', 128);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestampTz('locked_until')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['scope', 'user_id', 'key']);
        });

        Schema::create('account_deletion_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('pending'); // pending | processing | completed | cancelled | rejected
            $table->text('reason')->nullable();
            $table->timestampTz('scheduled_for')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->foreignUuid('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        // Append-only protection reusable by every ledger table.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_ledger_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Table % is append-only; % is not permitted', TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::unprepared('CREATE TRIGGER audit_logs_append_only BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION prevent_ledger_mutation()');
    }

    public function down(): void
    {
        foreach (['account_deletion_requests', 'idempotency_keys', 'system_settings', 'audit_logs', 'user_roles', 'role_permissions',
            'permissions', 'roles', 'user_devices', 'user_sessions', 'otp_verifications', 'sessions', 'password_reset_tokens', 'users'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS prevent_ledger_mutation() CASCADE');
    }
};
