<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live Operations Control: one row per alert already sent about a branch part of an order (see App\Domain\Operations).
 *
 *  - level `new`   the "new order" alert to the branch team (staff.order_new);
 *  - level `amber` the first reminder: the order waits longer than the amber time to be accepted;
 *  - level `red`   the second reminder: it waits longer than the red time.
 *
 * The unique key (fulfillment_id, level) is what makes every alert idempotent: a replayed event, a retried job or two overlapping scheduler
 * runs insert the row once, and only the run that inserted it sends anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ops_reminders')) {
            return;
        }
        Schema::create('ops_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('fulfillment_id')->constrained('order_fulfillments')->cascadeOnDelete();
            $table->string('level', 8);               // new | amber | red
            $table->timestampTz('sent_at');
            $table->unique(['fulfillment_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_reminders');
    }
};
