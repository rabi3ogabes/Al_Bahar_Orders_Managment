<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The weekly owner pack's send log (docs/guides/OWNER_PACK_AND_CUSTOMER_VALUE.md).
 *
 *  - owner_pack_sends: one row per send of one week's pack on one channel. A scheduled send is unique per ISO week and channel (a partial
 *    unique index), so two scheduler runs racing each other, or a retry after a crash, can never produce a second pack for the same week.
 *    A "test" send to one person is never scheduled and never counts for the week.
 *  - owner_pack_deliveries: one row per recipient of a send. A retry only goes to the recipients that did not receive it yet.
 * Aggregates and addresses only: the log never holds the pack's contents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_pack_sends', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('iso_week', 8);                                    // the week the pack covers, e.g. 2026-W47
            $table->string('channel', 12);                                    // email | whatsapp
            $table->string('kind', 12)->default('scheduled');                 // scheduled | test
            $table->string('status', 16)->default('sending');                 // sending | sent | partial | failed | not_connected
            $table->string('language', 2)->default('en');
            $table->unsignedSmallInteger('recipients_count')->default(0);
            $table->unsignedSmallInteger('sent_count')->default(0);
            $table->unsignedSmallInteger('failed_count')->default(0);
            $table->unsignedSmallInteger('attempts')->default(1);
            $table->string('error', 255)->nullable();
            $table->foreignUuid('triggered_by')->nullable()->constrained('users')->nullOnDelete(); // who pressed "Send test" (null: the scheduler)
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['created_at']);                                    // the log, newest first
        });
        DB::statement("CREATE UNIQUE INDEX owner_pack_sends_one_scheduled_per_week ON owner_pack_sends (iso_week, channel) WHERE kind = 'scheduled'");

        Schema::create('owner_pack_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('owner_pack_send_id')->constrained('owner_pack_sends')->cascadeOnDelete();
            $table->string('recipient', 191);                                 // an owner's e-mail address or phone number, as saved in the settings
            $table->string('status', 12);                                     // sent | failed
            $table->string('error', 255)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();

            $table->unique(['owner_pack_send_id', 'recipient']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_pack_deliveries');
        Schema::dropIfExists('owner_pack_sends');
    }
};
