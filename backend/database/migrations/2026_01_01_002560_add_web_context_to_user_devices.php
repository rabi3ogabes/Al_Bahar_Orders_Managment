<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Web push tokens: which web app registered the browser (the store, the web version of the mobile app or this dashboard) and the
        // browser family from its user agent, so the owner can read the device list and a failed test says which browser it was.
        Schema::table('user_devices', function (Blueprint $table) {
            $table->string('app', 16)->nullable()->after('platform');       // store | mobile-web | admin (null for the native apps)
            $table->string('browser', 40)->nullable()->after('app_version'); // e.g. "Chrome on Android"
        });
    }

    public function down(): void
    {
        Schema::table('user_devices', function (Blueprint $table) {
            $table->dropColumn(['app', 'browser']);
        });
    }
};
