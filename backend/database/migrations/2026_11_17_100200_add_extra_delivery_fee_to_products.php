<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An extra delivery fee per product (docs/api/CHECKOUT_FEES_V2.md section 3), for bulky items:
 *  - `extra_delivery_fee`: money added to the delivery fee of an order that contains the product (0 = none);
 *  - `extra_delivery_fee_mode`: `per_unit` charges it for every unit in the order, `per_line` charges it once whatever the quantity.
 * Every existing product gets 0 / per_unit, so nothing changes until an admin sets a fee. Safe to run again (it only adds what is missing).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'extra_delivery_fee')) {
            Schema::table('products', function (Blueprint $table) {
                $table->decimal('extra_delivery_fee', 12, 2)->default(0)->after('is_oversized');
                $table->string('extra_delivery_fee_mode', 16)->default('per_unit')->after('extra_delivery_fee');
            });
        }
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_extra_delivery_fee_check');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_extra_delivery_fee_check CHECK (extra_delivery_fee >= 0)');
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_extra_delivery_fee_mode_check');
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_extra_delivery_fee_mode_check CHECK (extra_delivery_fee_mode IN ('per_unit','per_line'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_extra_delivery_fee_mode_check');
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_extra_delivery_fee_check');
        if (Schema::hasColumn('products', 'extra_delivery_fee')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn(['extra_delivery_fee', 'extra_delivery_fee_mode']);
            });
        }
    }
};
