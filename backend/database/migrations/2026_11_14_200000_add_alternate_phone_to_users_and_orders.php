<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A second mobile number (E.164) the delivery driver may call when the main number is not answered.
        // Personal data: seen only by the customer, the driver of that delivery and the staff of the order's branch.
        Schema::table('users', function (Blueprint $table) {
            $table->string('alternate_phone', 20)->nullable()->after('phone');
        });
        // What the customer gave for THIS order (a snapshot, like customer_phone), so later profile edits never change an old delivery.
        Schema::table('orders', function (Blueprint $table) {
            $table->string('alternate_phone', 20)->nullable()->after('customer_phone');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('alternate_phone');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('alternate_phone');
        });
    }
};
