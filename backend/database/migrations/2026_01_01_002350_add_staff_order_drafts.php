<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-assisted orders: a staff member builds a cart ("staff_draft") on behalf of a customer without touching the
 * customer's own active cart. Drafts are excluded from the one-active-cart index, abandoned-cart journeys and expiry
 * because all of those only look at status = 'active'. Orders remember which staff member placed them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->foreignUuid('staff_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->jsonb('staff_options')->nullable()->after('loyalty_points_to_redeem'); // payment method, slots, notes
            $table->index(['staff_id', 'status', 'updated_at']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignUuid('placed_by_staff_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->index('placed_by_staff_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('placed_by_staff_id');
        });
        Schema::table('carts', function (Blueprint $table) {
            $table->dropIndex(['staff_id', 'status', 'updated_at']);
            $table->dropConstrainedForeignId('staff_id');
            $table->dropColumn('staff_options');
        });
    }
};
