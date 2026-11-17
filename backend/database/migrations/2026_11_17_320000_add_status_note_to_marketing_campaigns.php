<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * marketing_campaigns.status_note: a short code that says WHY a campaign ended without being sent (e.g. `missed_when_due`: its scheduled time
 * passed more than a day before it could go out, so a stale offer was not sent). The campaigns list translates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_campaigns', function (Blueprint $table) {
            $table->string('status_note', 48)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('marketing_campaigns', fn (Blueprint $table) => $table->dropColumn('status_note'));
    }
};
