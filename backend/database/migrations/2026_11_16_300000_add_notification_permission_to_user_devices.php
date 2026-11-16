<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the phone itself says about showing notifications (Android 13+ hides every push until the person allows it, so "Google accepted it"
 * is not "the person can see it"). The apps report it with the push token:
 *  - `granted`: the phone shows notifications;  `denied`: the person said no;  `not_asked`: the question was never put to the person;
 *  - null: not reported (an older app version, or a browser): unknown, never treated as off.
 * `notification_permission_at` is when the phone last said so. No data is changed: every existing row stays unknown until its app reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_devices', function (Blueprint $table) {
            $table->string('notification_permission', 12)->nullable()->after('browser');
            $table->timestampTz('notification_permission_at')->nullable()->after('notification_permission');
        });
        DB::statement("ALTER TABLE user_devices ADD CONSTRAINT user_devices_notification_permission_check CHECK (notification_permission IN ('granted','denied','not_asked'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE user_devices DROP CONSTRAINT IF EXISTS user_devices_notification_permission_check');
        Schema::table('user_devices', function (Blueprint $table) {
            $table->dropColumn(['notification_permission', 'notification_permission_at']);
        });
    }
};
