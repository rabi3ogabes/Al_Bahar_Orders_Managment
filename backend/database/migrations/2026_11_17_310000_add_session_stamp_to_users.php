<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users.session_stamp: the value every dashboard (browser) session of a staff member must carry. It is written into the session at sign-in and
 * compared on every request (EnsureStaff); rotating it (password change or reset, suspend, last branch removed) ends every other browser
 * session at once, whatever the session driver (database, Redis, file), and an old cookie stays dead even if the account is switched on again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('session_stamp', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('session_stamp'));
    }
};
