<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer value, worked out once and read many times (docs/guides/OWNER_PACK_AND_CUSTOMER_VALUE.md).
 *
 * One row per customer per scope. The scope is "all" (every branch: the numbers of a viewer who sees the whole company) or a branch id (what
 * the customer spent at THAT branch: the numbers of a branch-limited viewer, who must never see spend that happened elsewhere). The rows are
 * rebuilt in one pass, in the background and on demand, from the same sales definition as the dashboard (SalesBasis), so the lists never
 * scan all orders for a page view.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_value_rollups', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('scope', 36);                                      // "all" or a branch id
            $table->unsignedInteger('orders');                                // orders in the scope (a split order counts once per branch in a branch scope)
            $table->unsignedInteger('delivered_orders');                      // of those, how many reached the customer (delivered or collected)
            $table->decimal('spend', 14, 2);                                  // what the customer was charged in the scope (SalesBasis shares)
            $table->timestampTz('first_order_at');
            $table->timestampTz('last_order_at');
            $table->timestampTz('refreshed_at', 6);                          // microseconds: a rebuild tells its own rows from the previous run's

            $table->unique(['user_id', 'scope']);
            $table->index(['scope', 'spend']);                                // top customers of a scope
            $table->index(['scope', 'last_order_at']);                        // who has gone quiet
            $table->index('refreshed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_value_rollups');
    }
};
