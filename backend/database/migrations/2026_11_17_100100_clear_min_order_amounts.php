<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The minimum order amount of a delivery zone is no longer a rule (docs/api/CHECKOUT_FEES_V2.md section 2): nothing reads it and no screen or
 * API shows it. The column stays in this release (nullable, unused, always NULL) so a rollback of the code is harmless; this only clears the
 * numbers so no stale minimum survives anywhere (reports, exports, a future reader of the table).
 * `max_order_amount` (a different rule) and the referral programme's own minimum first order are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('branch_delivery_zones')->whereNotNull('min_order_amount')->update(['min_order_amount' => null]);
    }

    /** Nothing to restore: the old minimums were deliberately discarded and the rule they drove no longer exists. */
    public function down(): void {}
};
