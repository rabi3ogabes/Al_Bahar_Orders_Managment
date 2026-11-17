<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cash on delivery no longer costs extra (docs/api/CHECKOUT_FEES_V2.md section 1): the dashboard field "Cash on delivery fee" is gone and the
 * quote's `totals.cod_fee` is always "0.00". This deletes the number an admin may have saved so no stale value survives in the settings table.
 * Orders already placed keep their own stored `fee_breakdown.cod_fee` (history, invoices and order adjustments still read it).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')->where('group', 'checkout')->where('key', 'cod_fee')->delete();
        Cache::forget('system_settings:v1');
    }

    /** Nothing to restore: the saved fee was deliberately discarded and the rule it drove no longer exists. */
    public function down(): void {}
};
