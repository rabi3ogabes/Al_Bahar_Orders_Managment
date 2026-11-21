<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The daily close (docs/guides/OWNER_PACK_AND_CUSTOMER_VALUE.md): the day of one branch, looked at by a manager and signed off with a note.
 * One row per branch per Qatar business day. The numbers are copied into the row when the day is closed, so what the owner reads later is
 * exactly what the manager saw, even after a late cash hand-in or a refund changes the live figures. Aggregates only: nothing in here names a customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_closes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->date('business_date');                                   // the Qatar-local day that was closed
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('closed_at');
            $table->string('note', 1000)->nullable();                        // the manager's second look: required when the day had open points
            $table->jsonb('numbers');                                        // the day's figures as they were when it was closed
            $table->jsonb('flags');                                          // the open points the manager acknowledged ({"never_accepted": 2, ...})
            $table->timestampsTz();

            $table->unique(['branch_id', 'business_date']);                  // one close per branch per day: a double tap can never close it twice
            $table->index(['business_date', 'branch_id']);                   // "which branches closed yesterday" for the owner
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_closes');
    }
};
