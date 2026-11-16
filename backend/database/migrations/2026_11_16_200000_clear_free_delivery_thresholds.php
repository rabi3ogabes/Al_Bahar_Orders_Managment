<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Free above X QAR" is gone: every delivery pays its zone fee, and free delivery only comes from a free-delivery coupon code.
 * The column stays in this release (nullable, unused) so a rollback of the code is harmless; this only clears the numbers
 * so no stale threshold survives anywhere (reports, exports, a future reader of the table).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('branch_delivery_zones')->whereNotNull('free_delivery_threshold')->update(['free_delivery_threshold' => null]);
    }

    /** Nothing to restore: the old thresholds were deliberately discarded and the rule they drove no longer exists. */
    public function down(): void {}
};
