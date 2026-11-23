<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the SMS / WhatsApp provider answered: every "Send test message" from the dashboard, and every failed sign-in code delivery.
 * Never the message text (a sign-in code could be in it); the recipient is masked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_provider_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('channel', 12);
            $table->string('provider', 24);
            $table->string('purpose', 12);
            $table->string('recipient', 32);
            $table->string('status', 24);
            $table->string('provider_message_id', 100)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_detail', 255)->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['purpose', 'channel', 'created_at']);
        });
        DB::statement("ALTER TABLE message_provider_events ADD CONSTRAINT message_provider_events_enums CHECK (channel IN ('sms','whatsapp') AND purpose IN ('test','otp','message'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('message_provider_events');
    }
};
