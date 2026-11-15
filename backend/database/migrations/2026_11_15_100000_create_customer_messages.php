<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Messages that staff send to customers by hand (one by one or in bulk) from the customers pages: a notification (in-app + push) and/or a
 * WhatsApp message through n8n, built from a template and customised before sending. Every send is a batch; every customer × channel in it is
 * one row with its own result, so the history answers "what did we send this customer, when, and did it arrive".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_message_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('template_id')->nullable()->constrained('journey_templates')->nullOnDelete();
            $table->string('template_name', 120);                 // snapshot: the template may be renamed or deleted later
            $table->string('kind', 12);                           // reminder | offer | custom
            $table->string('source', 8);                          // single | bulk
            $table->jsonb('channels');                            // ["notification", "whatsapp"]
            $table->jsonb('content');                             // the texts as sent (after the admin's edits), deep link and coupon settings
            $table->jsonb('audience')->nullable();                // how the recipients were chosen (ids count / filters), for the record
            $table->unsignedInteger('recipients_count')->default(0);   // customers chosen
            $table->unsignedInteger('queued_count')->default(0);       // message rows still waiting to be sent
            $table->unsignedInteger('sent_count')->default(0);         // handed to the channel (includes delivered)
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);      // not sent on purpose (no consent, recently contacted, ...)
            $table->string('status', 12)->default('sending');          // scheduled | sending | done | cancelled
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['created_at']);
            $table->index(['created_by', 'created_at']);
            $table->index(['kind', 'created_at']);
        });
        DB::statement("CREATE INDEX customer_message_batches_due ON customer_message_batches (scheduled_at) WHERE status = 'scheduled'");

        Schema::create('customer_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('batch_id')->constrained('customer_message_batches')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 12);                        // notification | whatsapp
            $table->string('locale', 2);
            $table->string('title', 160)->nullable();
            $table->text('body')->nullable();                     // the exact text sent (filled at send time)
            $table->string('coupon_code', 48)->nullable();
            $table->string('status', 12)->default('queued');      // queued | sent | delivered | failed | skipped | cancelled
            $table->string('reason', 48)->nullable();             // why it was skipped or failed
            $table->string('external_id', 96)->nullable();        // the provider's message id
            $table->jsonb('meta')->nullable();                    // e.g. push devices reached
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();

            $table->unique(['batch_id', 'user_id', 'channel']);
            $table->index(['batch_id', 'status']);
            $table->index(['user_id', 'created_at']);             // a customer's history, newest first
            $table->index(['status', 'created_at']);
        });
        // "When did we last contact this customer?" for the whole customers list, and the recently-contacted guard.
        DB::statement('CREATE INDEX customer_messages_last_contact ON customer_messages (user_id, sent_at DESC) WHERE sent_at IS NOT NULL');
        DB::statement("CREATE INDEX customer_messages_queued ON customer_messages (batch_id) WHERE status = 'queued'");
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_messages');
        Schema::dropIfExists('customer_message_batches');
    }
};
