<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the phone apps say about their own push set-up. A phone that cannot start Firebase, cannot get a token or cannot register it has
 * no other way to tell anyone (it has no token to be pushed to), so it posts one short, sanitized report to POST /api/v1/push-diagnostics:
 * which app, which step failed (`stage`), a machine `code` the dashboard explains, and a clipped message. A phone that works reports
 * `registered`, which is how the dashboard shows "N phones registered". One row per install, app, stage and code per day: a repeat of the
 * same failure on the same day only raises `count` and refreshes `last_seen_at`. No person, token, API key or IP address is stored. Rows older
 * than 30 days are removed by push:prune-devices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_diagnostics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('app', 8);                       // customer | driver | staff
            $table->string('platform', 8);                  // android | ios
            $table->string('stage', 12);                    // options | init | permission | token | register | registered
            $table->string('code', 48);                     // machine code, e.g. api_key_blocked (explained on the dashboard)
            $table->string('message', 200)->nullable();     // clipped and scrubbed of anything that looks like a key or token
            $table->string('app_version', 32)->nullable();  // 1.0.0+12
            $table->string('os_version', 64)->nullable();
            $table->string('install_id', 64);               // the random id the app made for this install (not a person, not a device serial)
            $table->date('report_day');                     // server day (UTC) of the report: the dedupe window
            $table->unsignedInteger('count')->default(1);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampsTz();

            $table->unique(['install_id', 'app', 'stage', 'code', 'report_day'], 'push_diagnostics_once_a_day');
            $table->index(['app', 'stage', 'code'], 'push_diagnostics_group');
            $table->index('last_seen_at');
        });
        DB::statement("ALTER TABLE push_diagnostics ADD CONSTRAINT push_diagnostics_app_check CHECK (app IN ('customer','driver','staff'))");
        DB::statement("ALTER TABLE push_diagnostics ADD CONSTRAINT push_diagnostics_platform_check CHECK (platform IN ('android','ios'))");
        DB::statement("ALTER TABLE push_diagnostics ADD CONSTRAINT push_diagnostics_stage_check CHECK (stage IN ('options','init','permission','token','register','registered'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('push_diagnostics');
    }
};
