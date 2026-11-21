<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery promise, paid assembly and installation (docs/guides/DELIVERY_PROMISE_ASSEMBLY_AND_SHARING.md):
 *  - products.assembly_fee: what one unit costs to have assembled at the customer's door (QAR, NULL = assembly is not offered for the product);
 *  - products.lead_time_days: days the branch needs before the product can leave (made to order); 0 = ready now. It moves the promised date.
 *  - cart_items.assembly: the customer ticked "Add assembly" on this line (all its units); the server decides what it costs, never the client.
 *  - order_items.assembly_fee / assembly_total: the fee per unit AS PLACED (a later price change never touches an order) and what the line owes now
 *    (fee x units still on the line, so an adjustment, a cancelled or removed line takes its assembly fee with it);
 *  - orders.assembly_fee_total: the sum of the lines, part of the grand total like the service fee.
 * Every existing row keeps working unchanged (no fee, no lead time, no assembly). Safe to run again (it only adds what is missing).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'assembly_fee')) {
            Schema::table('products', function (Blueprint $table) {
                $table->decimal('assembly_fee', 12, 2)->nullable()->after('extra_delivery_fee_mode');
                $table->unsignedSmallInteger('lead_time_days')->default(0)->after('assembly_fee');
            });
        }
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_assembly_fee_check');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_assembly_fee_check CHECK (assembly_fee IS NULL OR assembly_fee >= 0)');
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_lead_time_days_check');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_lead_time_days_check CHECK (lead_time_days BETWEEN 0 AND 60)');

        if (! Schema::hasColumn('cart_items', 'assembly')) {
            Schema::table('cart_items', function (Blueprint $table) {
                $table->boolean('assembly')->default(false)->after('quantity');
            });
        }

        if (! Schema::hasColumn('order_items', 'assembly_fee')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->decimal('assembly_fee', 12, 2)->nullable()->after('line_total');
                $table->decimal('assembly_total', 12, 2)->default(0)->after('assembly_fee');
            });
        }
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_assembly_check');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_assembly_check CHECK ((assembly_fee IS NULL OR assembly_fee >= 0) AND assembly_total >= 0)');

        if (! Schema::hasColumn('orders', 'assembly_fee_total')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->decimal('assembly_fee_total', 12, 2)->default(0)->after('service_fee_total');
            });
        }
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_assembly_fee_total_check');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_assembly_fee_total_check CHECK (assembly_fee_total >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_assembly_fee_total_check');
        if (Schema::hasColumn('orders', 'assembly_fee_total')) {
            Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('assembly_fee_total'));
        }

        DB::statement('ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_assembly_check');
        if (Schema::hasColumn('order_items', 'assembly_fee')) {
            Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn(['assembly_fee', 'assembly_total']));
        }

        if (Schema::hasColumn('cart_items', 'assembly')) {
            Schema::table('cart_items', fn (Blueprint $table) => $table->dropColumn('assembly'));
        }

        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_lead_time_days_check');
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_assembly_fee_check');
        if (Schema::hasColumn('products', 'assembly_fee')) {
            Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['assembly_fee', 'lead_time_days']));
        }
    }
};
