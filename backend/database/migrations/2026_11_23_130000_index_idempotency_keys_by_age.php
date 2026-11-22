<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The driver app's outbox (docs/guides/OFFLINE_DRIVER_AND_STAFF_APPS.md) keeps one `idempotency_keys` row per delivery step it sends (scope
 * `delivery_step`), and `deliveries:prune-step-keys` removes the ones older than a week every night. This index lets that clean-up read only the old
 * rows of its own scope instead of the whole table. The lookup of a key itself already has the (scope, user_id, key) unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->index(['scope', 'created_at'], 'idempotency_keys_scope_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->dropIndex('idempotency_keys_scope_created_at_index');
        });
    }
};
