<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branch employees with a personal feature checklist.
 *
 *  - roles.personal_user_id / personal_branch_id: a role that belongs to ONE employee at ONE branch (the checklist an admin ticks for
 *    them). Authorisation is unchanged — it is an ordinary branch-scoped role row — but it is hidden from the Roles page and every role
 *    picker, can never be handed to anybody else, and goes away with the employee or the branch.
 *  - users.must_change_password: set when an admin creates the account or resets the password; the person must choose their own password
 *    before the dashboard or the staff API will let them do anything else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->uuid('personal_user_id')->nullable();
            $table->uuid('personal_branch_id')->nullable();
            $table->foreign('personal_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('personal_branch_id')->references('id')->on('branches')->cascadeOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX roles_personal_unique ON roles (personal_user_id, personal_branch_id) WHERE personal_user_id IS NOT NULL');
        DB::statement('ALTER TABLE roles ADD CONSTRAINT roles_personal_pair_check CHECK ((personal_user_id IS NULL) = (personal_branch_id IS NULL))');
        DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_personal_scope_check CHECK (personal_user_id IS NULL OR (scope = 'branch' AND is_system = false))");

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('must_change_password'));
        DB::statement('ALTER TABLE roles DROP CONSTRAINT IF EXISTS roles_personal_scope_check');
        DB::statement('ALTER TABLE roles DROP CONSTRAINT IF EXISTS roles_personal_pair_check');
        DB::statement('DROP INDEX IF EXISTS roles_personal_unique');
        Schema::table('roles', function (Blueprint $table) {
            $table->dropForeign(['personal_user_id']);
            $table->dropForeign(['personal_branch_id']);
            $table->dropColumn(['personal_user_id', 'personal_branch_id']);
        });
    }
};
