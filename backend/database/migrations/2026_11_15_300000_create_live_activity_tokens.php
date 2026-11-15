<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * iPhone Live Activity tokens (the order on the lock screen and in the Dynamic Island). Apple hands the app two kinds of token and
 * only the app can read them, so the app reports them here:
 *  - `activity`: the push token of ONE running Live Activity (one per order and phone). The server updates and ends that activity with it;
 *  - `start`: the phone's push-to-start token (iOS 17.2+), which lets the server start the activity for an order while the app is closed.
 * A token is a long hex string; it is unique, belongs to one customer and (for an activity) one of that customer's orders. Rows are
 * switched off, not deleted, when the activity ends or Apple says the token is gone, and pruned when old (see push:prune-devices).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_activity_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('order_id')->nullable()->constrained('orders')->cascadeOnDelete(); // null for a push-to-start token
            $table->string('kind', 8);                       // activity | start
            $table->string('token', 255);                    // hex, as Apple's push token
            $table->string('environment', 10);               // production | sandbox: which APNs host this token works on
            $table->string('device_id', 191)->nullable();    // the app's install id (same as user_devices.device_id)
            $table->string('locale', 5)->nullable();         // en | ar: the language the activity is written in
            $table->string('app_version', 32)->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('last_pushed_at')->nullable();
            $table->timestampTz('disabled_at')->nullable();
            $table->timestampsTz();

            $table->unique('token');
            $table->index(['order_id', 'kind', 'disabled_at']);
            $table->index(['user_id', 'kind', 'disabled_at']);
            $table->index('device_id');
        });
        DB::statement("ALTER TABLE live_activity_tokens ADD CONSTRAINT live_activity_tokens_kind_check CHECK (kind IN ('activity','start'))");
        DB::statement("ALTER TABLE live_activity_tokens ADD CONSTRAINT live_activity_tokens_env_check CHECK (environment IN ('production','sandbox'))");
        DB::statement("ALTER TABLE live_activity_tokens ADD CONSTRAINT live_activity_tokens_order_check CHECK ((kind = 'activity') = (order_id IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('live_activity_tokens');
    }
};
